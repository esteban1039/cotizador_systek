<?php

namespace Tests\Unit;

use App\Domain\CompanyProfile;
use PHPUnit\Framework\TestCase;

final class CompanyProfileTest extends TestCase
{
    public function test_missing_without_a_version_lists_all_required_fields(): void
    {
        $this->assertSame(
            ['legal_name', 'nit', 'address', 'phone', 'email', 'signer_name', 'signer_title', 'bank_account'],
            CompanyProfile::missing(null)
        );
    }

    public function test_missing_after_seeder_only_lacks_bank_account(): void
    {
        $profile = [
            'legal_name' => 'Systek Company S.A.S.', 'nit' => '901704107-1',
            'address' => 'Cra 75 # 28-21, Belén, Medellín', 'phone' => '3045869886',
            'email' => 'stip@systekcompany.io', 'signer_name' => 'Jhonatan Stip Gutierrez',
            'signer_title' => 'Gerente', 'bank_account' => false,
        ];
        $this->assertSame(['bank_account'], CompanyProfile::missing($profile));
    }

    public function test_missing_is_empty_when_complete(): void
    {
        $profile = [
            'legal_name' => 'Systek Company S.A.S.', 'nit' => '901704107-1',
            'address' => 'Cra 75 # 28-21, Belén, Medellín', 'phone' => '3045869886',
            'email' => 'stip@systekcompany.io', 'signer_name' => 'Jhonatan Stip Gutierrez',
            'signer_title' => 'Gerente', 'bank_account' => true,
        ];
        $this->assertSame([], CompanyProfile::missing($profile));
    }

    public function test_trade_name_and_website_are_never_required(): void
    {
        $profile = [
            'legal_name' => 'Systek Company S.A.S.', 'nit' => '901704107-1',
            'address' => 'Cra 75 # 28-21, Belén, Medellín', 'phone' => '3045869886',
            'email' => 'stip@systekcompany.io', 'signer_name' => 'Jhonatan Stip Gutierrez',
            'signer_title' => 'Gerente', 'bank_account' => true,
            'trade_name' => null, 'website' => null,
        ];
        $this->assertSame([], CompanyProfile::missing($profile));
    }
}
