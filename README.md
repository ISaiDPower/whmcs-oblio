# WHMCS Oblio Integration Module

A WHMCS addon module that integrates with [Oblio.eu](https://www.oblio.eu) for automated invoice and proforma invoice creation. Designed to support **Romanian e-Factura regulations**.

## Features

- **Automatic proforma sync** — When a new unpaid invoice is created in WHMCS, a proforma is automatically created in Oblio.
- **Automatic invoice sync** — When an invoice is paid in WHMCS, a final invoice is created in Oblio (critical for e-Factura compliance).
- **CUI/CIF field mapping** — Administrators can select which custom client profile field stores the client's CUI/CIF (tax identification number).
- **Manual sync** — Manually sync any WHMCS invoice to Oblio from the admin panel.
- **Sync log** — View the history of all synced documents with status and error tracking.
- **API connection test** — Verify your Oblio API credentials directly from the admin panel.
- **Configurable VAT** — Set the default VAT percentage for line items.
- **Multi-language documents** — Choose the language for documents created in Oblio (RO, EN, FR, DE).
- **Country-agnostic** — Invoices are always sent to Oblio regardless of the client's country, as required by Romanian regulations.

## Requirements

- WHMCS 7.0 or later
- PHP 7.2 or later with cURL extension
- An Oblio.eu account with API access

## Installation

1. Copy the `modules/addons/oblio/` directory to your WHMCS installation's `modules/addons/` directory.

2. Log in to WHMCS Admin and go to **Setup → Addon Modules**.

3. Find **Oblio Integration** and click **Activate**.

4. Configure the module with your Oblio API credentials:
   - **API Email (Client ID)** — Your Oblio account email
   - **API Secret** — Your Oblio API secret key
   - **Company CIF** — Your company's CIF/CUI as registered in Oblio
   - **Invoice Series** — The series name for invoices (e.g., `FCT`)
   - **Proforma Series** — The series name for proformas (e.g., `PFT`)
   - **CUI/CIF Client Field** — Select the custom client field that stores the tax ID
   - **Document Language** — Language for Oblio documents
   - **Enable Proforma Sync** — Toggle automatic proforma creation
   - **Enable Invoice Sync** — Toggle automatic invoice creation (recommended: always on)
   - **Default VAT %** — Default VAT percentage (default: 19%)

5. Click **Save Changes**.

## How It Works

### Invoice Flow (Romania)

1. **Customer places an order** → WHMCS creates an unpaid invoice (proforma).
   - If *Enable Proforma Sync* is on, the module sends a proforma to Oblio via the `InvoiceCreated` hook.

2. **Customer pays the invoice** → WHMCS marks the invoice as paid.
   - If *Enable Invoice Sync* is on, the module creates a final invoice in Oblio via the `InvoicePaid` hook.
   - This step is **mandatory for Romanian e-Factura compliance**.

### CUI/CIF Detection

The module reads the client's CUI/CIF (tax identification number) from a custom profile field. During configuration, the administrator selects which custom client field contains this data from a dropdown populated with all available custom client fields in WHMCS.

### Duplicate Prevention

The module tracks all synced documents in a database table (`mod_oblio_invoices`). Before sending a document to Oblio, it checks if the invoice has already been synced for that document type to avoid creating duplicates.

## Admin Panel

Access the module admin panel at **Addons → Oblio Integration**. From here you can:

- **Test Connection** — Verify API credentials and see registered companies
- **Manual Sync** — Send any invoice to Oblio as either a proforma or invoice
- **View Sync Log** — See the history of all sync operations with status

## File Structure

```
modules/addons/oblio/
├── oblio.php              # Main module (config, activate, deactivate, admin output)
├── hooks.php              # WHMCS hooks (InvoiceCreated, InvoicePaid)
├── lib/
│   ├── OblioApi.php       # Oblio API client (OAuth2, document CRUD)
│   └── WhmcsHelper.php    # WHMCS data extraction and transformation
└── lang/
    └── english.php        # English language strings
```

## API Reference

This module uses the [Oblio API](https://www.oblio.eu/api):

- **Authentication**: OAuth2 client credentials grant
- **Create documents**: `POST /api/docs/{type}` (type: `invoice` or `proforma`)
- **Get documents**: `GET /api/docs/{type}`
- **Nomenclature**: `GET /api/nomenclature/companies`, `/series`, `/vat_rates`

## License

This project is provided as-is for integration purposes.