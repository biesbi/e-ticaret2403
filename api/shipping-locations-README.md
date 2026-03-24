Shipping locations import

This project reads checkout location lists from these tables:

- `shipping_cities`
- `shipping_districts`
- `shipping_neighborhoods`
- `shipping_streets`

Expected JSON format

See [shipping_locations.template.json](c:\Users\biesbi\Desktop\dist - Copy\api\data\shipping_locations.template.json) for the structure.

Each object must include stable numeric ids:

- city: `id`, `name`, optional `code`
- district: `id`, `name`
- neighborhood: `id`, `name`

Import command

```powershell
C:\xampp\php\php.exe api\scripts\import_shipping_locations.php api\data\shipping_locations.json
```

Notes

- The import truncates the shipping location tables before reloading them.
- `shipping.php` already serves these tables to the frontend.
- If you have a 2026 CSV or JSON dump, convert it to the template shape and import it with the command above.

HeidiSQL import from `il-ilce-mahalle-sokak-veritabani-main`

If you imported the repo dump into MySQL and now have these source tables:

- `iller`
- `ilceler`
- `mahalleler`
- `csbms`

run this SQL file in HeidiSQL:

- [import_nvi_locations_from_main_repo.sql](c:\Users\biesbi\Desktop\dist - Copy\api\sql\import_nvi_locations_from_main_repo.sql)

That SQL maps the source tables into:

- `shipping_cities`
- `shipping_districts`
- `shipping_neighborhoods`
- `shipping_streets`

API endpoints

- `GET /api/shipping/cities`
- `GET /api/shipping/districts/{cityId}`
- `GET /api/shipping/neighborhoods/{districtId}`
- `GET /api/shipping/streets/{neighborhoodId}`

One-command import from the repo dump

If `il-ilce-mahalle-sokak-veritabani-main` exists in the project root, you can import and map everything with:

```powershell
powershell -ExecutionPolicy Bypass -File api\scripts\setup_shipping_from_repo.ps1
```

If MySQL has a password:

```powershell
powershell -ExecutionPolicy Bypass -File api\scripts\setup_shipping_from_repo.ps1 -Password "YOUR_PASSWORD"
```
