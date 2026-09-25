# JARVIS Cotizador Systek — backend

Laravel 13, PHP 8.5 y PostgreSQL. Las dependencias y Laravel Boost ya están instalados. Usa Docker Compose desde la raíz del repositorio; no es necesario instalar PHP en el host.

- Lee `../plan_maestro_jarvis_cotizador_systek.md` y `../docs/primera-iteracion.md` para el alcance.
- Ejecuta `docker compose run --rm -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: api php artisan test --compact` y `docker compose run --rm api vendor/bin/pint --test` al cambiar el backend.
- No calcules dinero con float. Usa centavos enteros y resultados decimales en cadenas.
- Los precios históricos nunca se convierten en vigentes automáticamente.
- No habilites emisión ni envío sin implementar aprobación humana y reglas oficiales.
- Nunca incluyas `.env`, credenciales o datos bancarios en código, logs o contexto de IA.
- Los datos de DemoSeeder son ficticios y solo se permiten en local/testing.
- Usa patrón repositorio: contratos específicos en `app/Repositories/Contracts`, implementaciones en `app/Repositories/Eloquent` e inyección por constructor. No construyas consultas en controladores ni en el dominio; no expongas query builders en contratos. Conserva las transacciones y el orden de bloqueos. Consulta `../docs/arquitectura.md`.

- Antes de cualquier prueba con RefreshDatabase, usa DB_CONNECTION y DB_DATABASE explícitos: SQLite :memory: o PostgreSQL systek_test. Nunca la base systek. Las pruebas de navegador usan compose.e2e.yaml y systek_e2e. No retires la guardia de tests/TestCase.php.
