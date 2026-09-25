# API JARVIS Cotizador Systek

Backend Laravel 13 / PHP 8.5. La instalación, los endpoints y las pruebas están documentados en [el README del proyecto](../README.md).

- `app/Domain/Quotes/QuoteCalculator.php`: cálculo en centavos, sin float.
- `app/Http/Controllers/QuoteController.php`: resolución de precios y guardado de borradores.
- `database/migrations/2026_09_18_000001_create_commercial_core.php`: clientes, sedes, catálogo, versiones de precio y borradores.
- `database/seeders/DemoSeeder.php`: ejemplo ficticio de CCTV.
- `tests/`: pruebas de cálculo y API.

El acceso con token compartido es temporal y exclusivo de desarrollo. La emisión está bloqueada hasta implementar usuarios, reglas comerciales y aprobación.
