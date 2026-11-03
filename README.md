# Secure PDA PDF Watermark

A small WordPress plugin that creates a personalised PDF at download time. It checks PDA Gold access before reading the protected attachment, adds the current WordPress username, email address and download date to every page, and streams the result without changing the original PDF or saving a public copy.

## Requirements

- WordPress 6.0 or later
- PHP 8.1 or later
- PDA Gold installed and active
- Composer dependency `setasign/fpdi-tcpdf`

## Installation

The plugin source ZIP does not include Composer's `vendor` directory. Build the complete plugin before uploading it:

1. Extract this plugin folder on a computer with PHP 8.1+ and Composer.
2. In the `secure-pda-pdf-watermark` folder, run:

   ```bash
   composer install --no-dev --classmap-authoritative
   ```

3. Zip the complete `secure-pda-pdf-watermark` folder, including the generated `vendor` directory.
4. Upload that ZIP via **WordPress → Plugins → Add New Plugin → Upload Plugin**, then activate it.

Alternatively, if Composer is available on the web host, upload the source folder and run `composer install --no-dev --classmap-authoritative` inside it.

## Usage

Add this shortcode to a page or post:

```text
[secure_pda_pdf id="17977"]
```

Replace `17977` with the Media Library attachment ID. Optional button label:

```text
[secure_pda_pdf id="17977" label="Download your personalised PDF"]
```

The shortcode only displays the link to logged-in users who currently pass PDA Gold's permission check. The permission is checked again when the download is requested.

## Watermark

Every page receives a diagonal, light-grey watermark containing:

- `PERSONAL COPY`
- WordPress username (`user_login`)
- WordPress account email address
- Download date, using the site's WordPress date format and timezone

The original Media Library PDF is not modified. The generated copy is streamed to the browser and is not saved in the Media Library or public uploads directory.

## Important notes

- This plugin intentionally refuses to serve attachments that PDA Gold does not identify as protected.
- The permission integration uses the two PDA Gold classes and method supplied by the site administrator. If PDA Gold changes its internal API in a future release, this integration may need updating.
- Some PDFs cannot be imported by FPDI, for example certain encrypted PDFs or files using unsupported compression/features. Those downloads will fail with a generic error; details are written to the PHP error log.
- Rewriting a PDF invalidates any existing digital signature in the downloaded copy.
- The watermark includes the account email address and is visible to anyone who receives the downloaded PDF.
- A WordPress nonce helps protect the download endpoint from forged requests; it is not a substitute for the login and PDA Gold permission checks, which are performed independently.
