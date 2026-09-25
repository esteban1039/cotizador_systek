<?php

namespace App\Infrastructure\Drive;

use App\Application\Drive\DriveInventoryClient;
use Illuminate\Support\Facades\Http;
use Throwable;

final class GoogleDriveInventoryClient implements DriveInventoryClient
{
    private const ENDPOINT = 'https://www.googleapis.com/drive/v3/files';

    private const FOLDER = 'application/vnd.google-apps.folder';

    private const SHORTCUT = 'application/vnd.google-apps.shortcut';

    private const FIELDS = 'id,name,mimeType,modifiedTime,size,md5Checksum,parents,webViewLink,shortcutDetails';

    public function inventory(string $rootFolderId, int $maxFiles, int $maxDepth): array
    {
        $report = [
            'schema_version' => 1, 'generated_at' => now()->toIso8601String(),
            'root_folder_id' => $rootFolderId, 'root' => null, 'status' => 'incomplete',
            'limits' => ['max_files' => $maxFiles, 'max_depth' => $maxDepth],
            'files' => [], 'issues' => [],
            'counts' => ['files' => 0, 'folders' => 0, 'shortcuts' => 0, 'requests' => 0],
        ];
        if (! config('drive.enabled')) {
            $report['issues'][] = ['code' => 'disabled'];

            return $report;
        }
        if (! $this->validId($rootFolderId) || $maxFiles < 1 || $maxFiles > 10000 || $maxDepth < 1 || $maxDepth > 30) {
            $report['issues'][] = ['code' => 'invalid_configuration'];

            return $report;
        }
        $token = $this->token();
        if ($token === null) {
            $report['issues'][] = ['code' => 'invalid_token_file'];

            return $report;
        }
        $root = $this->fetch($token, '/'.$rootFolderId, ['fields' => self::FIELDS, 'supportsAllDrives' => 'true'], $report, $rootFolderId);
        if ($root === null) {
            return $report;
        }
        if (($root['id'] ?? null) !== $rootFolderId || ($root['mimeType'] ?? null) !== self::FOLDER) {
            $report['issues'][] = ['code' => 'root_not_folder'];

            return $report;
        }
        $report['root'] = $this->metadata($root);
        $queue = [[$rootFolderId, 0]];
        $seen = [$rootFolderId => true];
        for ($index = 0; $index < count($queue); $index++) {
            [$folder, $depth] = $queue[$index];
            $pageToken = null;
            $pages = [];
            do {
                // Empty pages and cyclic page tokens must not create an unbounded crawl.
                if ($report['counts']['requests'] >= $maxFiles + 100) {
                    $report['issues'][] = ['code' => 'request_limit', 'folder_id' => $folder];
                    break 2;
                }
                $query = [
                    'q' => "'".$folder."' in parents and trashed = false", 'pageSize' => 100,
                    'fields' => 'nextPageToken,incompleteSearch,files('.self::FIELDS.')',
                    'spaces' => 'drive', 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
                ];
                if ($pageToken !== null) {
                    $query['pageToken'] = $pageToken;
                }
                $page = $this->fetch($token, '', $query, $report, $folder);
                if ($page === null) {
                    break;
                }
                if (($page['incompleteSearch'] ?? false) === true) {
                    $report['issues'][] = ['code' => 'incomplete_search', 'folder_id' => $folder];
                }
                if (! isset($page['files']) || ! is_array($page['files']) || ! array_is_list($page['files'])) {
                    $report['issues'][] = ['code' => 'invalid_response', 'folder_id' => $folder];
                    break;
                }
                foreach ($page['files'] as $file) {
                    if (! is_array($file) || ! $this->validId($file['id'] ?? null) || ! is_string($file['name'] ?? null) || ! is_string($file['mimeType'] ?? null) || ! is_array($file['parents'] ?? null) || ! in_array($folder, $file['parents'], true)) {
                        $report['issues'][] = ['code' => 'invalid_or_out_of_scope_file', 'folder_id' => $folder];

                        continue;
                    }
                    if (isset($seen[$file['id']])) {
                        continue;
                    }
                    if (count($report['files']) >= $maxFiles) {
                        $report['issues'][] = ['code' => 'max_files', 'folder_id' => $folder];
                        break 3;
                    }
                    $seen[$file['id']] = true;
                    $report['files'][] = $this->metadata($file) + ['depth' => $depth + 1];
                    if ($file['mimeType'] === self::FOLDER) {
                        $report['counts']['folders']++;
                        if ($depth + 1 >= $maxDepth) {
                            $report['issues'][] = ['code' => 'max_depth', 'folder_id' => $file['id']];
                        } else {
                            $queue[] = [$file['id'], $depth + 1];
                        }
                    } elseif ($file['mimeType'] === self::SHORTCUT) {
                        $report['counts']['shortcuts']++;
                    }
                }
                $pageToken = $page['nextPageToken'] ?? null;
                if ($pageToken !== null && (! is_string($pageToken) || $pageToken === '' || isset($pages[$pageToken]))) {
                    $report['issues'][] = ['code' => 'invalid_pagination', 'folder_id' => $folder];
                    break;
                }
                if ($pageToken !== null) {
                    $pages[$pageToken] = true;
                }
            } while ($pageToken !== null);
        }
        $report['counts']['files'] = count($report['files']);
        $report['status'] = $report['issues'] === [] ? 'complete' : 'incomplete';

        return $report;
    }

    private function fetch(string $token, string $suffix, array $query, array &$report, string $folder): ?array
    {
        $report['counts']['requests']++;
        try {
            // Redirects disabled: bearer credentials must never follow an external Location.
            $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(20)
                ->withOptions(['allow_redirects' => false])->get(self::ENDPOINT.$suffix, $query);
            if (! $response->successful()) {
                $report['issues'][] = ['code' => 'http_error', 'folder_id' => $folder, 'http_status' => $response->status()];

                return null;
            }
            $data = $response->json();
            if (! is_array($data)) {
                $report['issues'][] = ['code' => 'invalid_response', 'folder_id' => $folder];

                return null;
            }

            return $data;
        } catch (Throwable) {
            // Do not propagate transport exception messages or response bodies (may contain tokens).
            $report['issues'][] = ['code' => 'transport_error', 'folder_id' => $folder];

            return null;
        }
    }

    private function token(): ?string
    {
        $path = config('drive.access_token_file');
        $private = realpath(storage_path('app/private'));
        if (! is_string($path) || $path === '' || $private === false || is_link($path)) {
            return null;
        }
        $real = realpath($path);
        if ($real === false || ! str_starts_with($real, $private.DIRECTORY_SEPARATOR) || ! is_file($real) || (fileperms($real) & 0777) !== 0600 || filesize($real) > 16384) {
            return null;
        }
        $token = @file_get_contents($real);
        if ($token === false || ! preg_match('/^[A-Za-z0-9._~+\\/=-]+$/D', trim($token))) {
            return null;
        }

        return trim($token);
    }

    private function validId(mixed $id): bool
    {
        return is_string($id) && (bool) preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $id);
    }

    private function metadata(array $file): array
    {
        return array_intersect_key($file, array_flip(explode(',', self::FIELDS)));
    }
}
