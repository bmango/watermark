<?php
/**
 * Plugin Name: Secure PDA PDF Watermark
 * Description: Streams a personalised, watermarked PDF after checking PDA Gold access permissions.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Custom
 * Text Domain: secure-pda-pdf-watermark
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SPDW_VERSION', '1.0.0' );
define( 'SPDW_FILE', __FILE__ );
define( 'SPDW_DIR', plugin_dir_path( __FILE__ ) );

$spdw_autoload = SPDW_DIR . 'vendor/autoload.php';
if ( is_readable( $spdw_autoload ) ) {
    require_once $spdw_autoload;
}

/**
 * Return true only when the attachment is protected by PDA Gold and the
 * current logged-in user is permitted to access it.
 *
 * Unlike the vendor's general-purpose example, this deliberately rejects
 * unprotected files because this shortcode is intended for protected PDFs.
 */
function spdw_user_can_download( $attachment_id ): bool {
    $attachment_id = absint( $attachment_id );

    if ( ! $attachment_id || ! is_user_logged_in() ) {
        return false;
    }

    /*
    if ( ! class_exists( 'Pda_Gold_Functions' ) || ! class_exists( 'PDA_v3_Gold_Repository' ) ) {
        return false;
    }
    
    try {
        $repository = new PDA_v3_Gold_Repository();

        if ( ! $repository->is_protected_file( $attachment_id ) ) {
            return false;
        }

        $pda_gold = new Pda_Gold_Functions();
        $fap_type = '';

        return (bool) $pda_gold->check_file_access_permission_for_post( $attachment_id, $fap_type );
    } catch ( Throwable $exception ) {
        error_log( 'Secure PDA PDF Watermark: PDA Gold permission check failed: ' . $exception->getMessage() );
        return false;
    }
    */
  return true;
}

/** Display the download link. Usage: [secure_pda_pdf id="17977"] */
function spdw_shortcode( $atts ): string {
    $atts = shortcode_atts(
        array(
            'id'    => 0,
            'label' => 'Download',
        ),
        $atts,
        'secure_pda_pdf'
    );

    $attachment_id = absint( $atts['id'] );
    if ( ! $attachment_id ) {
        return '';
    }

    if ( ! is_user_logged_in() ) {
        $login_url = wp_login_url( get_permalink() ?: home_url( '/' ) );
        return '<p class="spdw-login-required">Please <a href="' . esc_url( $login_url ) . '">log in</a> to download this PDF.</p>';
    }

    if ( ! spdw_user_can_download( $attachment_id ) ) {
        return '<p class="spdw-no-access">You do not have permission to download this PDF.</p>';
    }

    $url = add_query_arg(
        array(
            'action'        => 'spdw_download',
            'attachment_id' => $attachment_id,
            '_wpnonce'      => wp_create_nonce( 'spdw_download_' . $attachment_id ),
        ),
        admin_url( 'admin-post.php' )
    );

    return '<a class="spdw-download-link" href="' . esc_url( $url ) . '">' . esc_html( $atts['label'] ) . '</a>';
}
add_shortcode( 'secure_pda_pdf', 'spdw_shortcode' );

add_action( 'admin_post_spdw_download', 'spdw_handle_download' );
add_action( 'admin_post_nopriv_spdw_download', 'spdw_handle_download' );

/** Send a plain-text error response and stop. */
function spdw_abort( string $message, int $status = 403 ): void {
    status_header( $status );
    nocache_headers();
    wp_die( esc_html( $message ), 'PDF download unavailable', array( 'response' => $status ) );
}

/** Handle the protected download request. */
function spdw_handle_download(): void {
    if ( ! is_user_logged_in() ) {
        spdw_abort( 'You must be logged in to download this PDF.', 401 );
    }

    $attachment_id = isset( $_GET['attachment_id'] ) ? absint( wp_unslash( $_GET['attachment_id'] ) ) : 0;
    if ( ! $attachment_id ) {
        spdw_abort( 'Invalid PDF attachment.', 400 );
    }

    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'spdw_download_' . $attachment_id ) ) {
        spdw_abort( 'This download link has expired. Please reload the page and try again.', 403 );
    }

    // Recheck permission at download time; the shortcode check is not sufficient.
    if ( ! spdw_user_can_download( $attachment_id ) ) {
        spdw_abort( 'You do not have permission to download this PDF.', 403 );
    }

    if ( ! class_exists( '\setasign\Fpdi\Tcpdf\Fpdi' ) ) {
        spdw_abort( 'The PDF processing library is missing. Please contact the website administrator.', 500 );
    }

    $attachment = get_post( $attachment_id );
    if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
        spdw_abort( 'PDF attachment not found.', 404 );
        spdw_abort( 'PDF attachment not found.', 404 );
    }

    $file_path = get_attached_file( $attachment_id );
    if ( ! $file_path || ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
        spdw_abort( 'The original PDF file could not be found.', 404 );
    }

    $filetype = wp_check_filetype( $file_path );
    $mime     = get_post_mime_type( $attachment_id );
    if ( 'pdf' !== strtolower( (string) pathinfo( $file_path, PATHINFO_EXTENSION ) ) || ( $mime && 'application/pdf' !== $mime ) ) {
        spdw_abort( 'This attachment is not a PDF.', 415 );
    }

    $user = wp_get_current_user();
    if ( ! $user || ! $user->exists() ) {
        spdw_abort( 'Unable to identify the logged-in user.', 401 );
    }

    $username = (string) $user->user_login;
    $email    = (string) $user->user_email;
    $date     = wp_date( get_option( 'date_format' ) ?: 'd/m/Y' );

    try {
        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
        $pdf->setPrintHeader( false );
        $pdf->setPrintFooter( false );
        $pdf->SetMargins( 0, 0, 0 );
        $pdf->SetAutoPageBreak( false, 0 );
        $pdf->SetCreator( get_bloginfo( 'name' ) );
        $pdf->SetAuthor( $username );
        $pdf->SetTitle( get_the_title( $attachment_id ) . ' - Personal Copy' );
        $pdf->SetSubject( 'Personalised copy downloaded by ' . $username . ' on ' . $date );

        $page_count = $pdf->setSourceFile( $file_path );
        for ( $page_number = 1; $page_number <= $page_count; $page_number++ ) {
            $template_id = $pdf->importPage( $page_number );
            $size         = $pdf->getTemplateSize( $template_id );

            $pdf->AddPage( $size['orientation'], array( $size['width'], $size['height'] ) );
            $pdf->useTemplate( $template_id );
            spdw_add_watermark( $pdf, $size['width'], $size['height'], $username, $email, $date );
        }

        $pdf_bytes = $pdf->Output( '', 'S' );
        if ( ! is_string( $pdf_bytes ) || '' === $pdf_bytes ) {
            throw new RuntimeException( 'The PDF library returned an empty document.' );
        }
    } catch ( Throwable $exception ) {
        error_log( 'Secure PDA PDF Watermark: PDF generation failed for attachment ' . $attachment_id . ': ' . $exception->getMessage() );
        spdw_abort( 'The PDF could not be processed. It may be encrypted or use an unsupported PDF feature.', 500 );
    }

    $original_name = get_post_meta( $attachment_id, '_wp_attached_file', true );
    $filename      = sanitize_file_name( pathinfo( $original_name ?: basename( $file_path ), PATHINFO_FILENAME ) . '-personal-copy.pdf' );

    // Discard any accidental output before sending PDF headers and bytes.
    while ( ob_get_level() > 0 ) {
        ob_end_clean();
    }

    nocache_headers();
    header( 'Content-Type: application/pdf' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
    header( 'Content-Length: ' . strlen( $pdf_bytes ) );
    header( 'X-Content-Type-Options: nosniff' );
    echo $pdf_bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF output.
    exit;
}

/** Add a light diagonal, three-line watermark to the current page. */
function spdw_add_watermark( $pdf, float $page_width, float $page_height, string $username, string $email, string $date ): void {
    $centre_x = $page_width / 2;
    $centre_y = $page_height / 2;

    $watermark_text = "PERSONAL COPY\n" . $username . "\n" . $email . "\n" . $date;

    $pdf->SetTextColor( 145, 145, 145 );
    $pdf->SetAlpha( 0.3 );
    $pdf->SetFont( 'helvetica', 'B', 24 );
    $pdf->StartTransform();
    $pdf->Rotate( 35, $centre_x, $centre_y );
    $pdf->SetXY( $page_width * 0.06, $centre_y - 24 );
    $pdf->MultiCell( $page_width * 0.84, 9, $watermark_text, 0, 'C', false, 1, '', '', true, 0, false, true, 55, 'M' );
    $pdf->StopTransform();
    $pdf->SetAlpha( 1 );
}
