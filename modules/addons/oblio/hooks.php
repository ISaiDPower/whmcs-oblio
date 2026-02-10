<?php

/**
 * Oblio Integration Module - WHMCS Hooks
 *
 * Registers hooks for automatic invoice/proforma synchronization with Oblio.
 *
 * Hook: InvoiceCreated  -> Creates a proforma in Oblio (when enabled)
 * Hook: InvoicePaid     -> Creates an invoice in Oblio (critical for e-Factura)
 *
 * @see https://developers.whmcs.com/addon-modules/hooks/
 * @see https://developers.whmcs.com/hooks-reference/invoices-and-quotes/
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Module\Addon\Oblio\OblioApi;
use WHMCS\Module\Addon\Oblio\WhmcsHelper;

require_once __DIR__ . '/lib/OblioApi.php';
require_once __DIR__ . '/lib/WhmcsHelper.php';

/**
 * Retrieve the Oblio addon module settings.
 *
 * @return array Module settings or empty array on failure
 */
function oblio_get_module_settings()
{
    try {
        if (class_exists('\\WHMCS\\Database\\Capsule')) {
            $settings = \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->where('module', 'oblio')
                ->pluck('value', 'setting')
                ->toArray();
            return $settings;
        }
    } catch (\Exception $e) {
        logActivity('Oblio: Failed to load module settings: ' . $e->getMessage());
    }
    return [];
}

/**
 * Send a document to Oblio.
 *
 * @param int    $invoiceId WHMCS invoice ID
 * @param string $docType   'proforma' or 'invoice'
 * @param array  $settings  Module settings
 */
function oblio_send_document($invoiceId, $docType, array $settings)
{
    try {
        if (empty($settings['api_email']) || empty($settings['api_secret'])) {
            logActivity('Oblio: API credentials not configured. Skipping ' . $docType . ' for invoice #' . $invoiceId);
            return;
        }
        if (empty($settings['company_cif'])) {
            logActivity('Oblio: Company CIF not configured. Skipping ' . $docType . ' for invoice #' . $invoiceId);
            return;
        }

        $seriesName = ($docType === 'proforma') ? ($settings['proforma_series'] ?? '') : ($settings['invoice_series'] ?? '');
        if (empty($seriesName)) {
            logActivity('Oblio: ' . ucfirst($docType) . ' series not configured. Skipping invoice #' . $invoiceId);
            return;
        }

        // Check if already synced to avoid duplicates
        if (WhmcsHelper::isSynced($invoiceId, $docType)) {
            logActivity('Oblio: Invoice #' . $invoiceId . ' already synced as ' . $docType . '. Skipping.');
            return;
        }

        $api = new OblioApi($settings['api_email'], $settings['api_secret']);

        // When creating an invoice, check if a proforma was previously synced for this
        // WHMCS invoice. If so, create the invoice referencing the proforma (Romanian flow).
        if ($docType === 'invoice') {
            $proforma = WhmcsHelper::getSyncedProforma($invoiceId);
            if ($proforma && !empty($proforma->oblio_series) && !empty($proforma->oblio_number)) {
                $payload = [
                    'cif'               => $settings['company_cif'],
                    'seriesName'        => $seriesName,
                    'referenceDocument' => [
                        'type'       => 'Proforma',
                        'seriesName' => $proforma->oblio_series,
                        'number'     => $proforma->oblio_number,
                    ],
                ];

                $response = $api->createDocument('invoice', $payload);

                $oblioSeries = isset($response['data']['seriesName']) ? $response['data']['seriesName'] : $seriesName;
                $oblioNumber = isset($response['data']['number']) ? $response['data']['number'] : '';

                WhmcsHelper::logSync($invoiceId, $docType, $oblioSeries, $oblioNumber, 'success');
                logActivity('Oblio: Invoice created from proforma for invoice #' . $invoiceId . ': ' . $oblioSeries . ' #' . $oblioNumber);

                // Auto-send to SPV (e-Factura) if enabled
                if (!empty($settings['enable_spv']) && $settings['enable_spv'] === 'on'
                    && !empty($oblioSeries) && !empty($oblioNumber)) {
                    try {
                        $spvResponse = $api->sendToSPV($settings['company_cif'], $oblioSeries, $oblioNumber);
                        $spvSent = isset($spvResponse['data']['sent']) && $spvResponse['data']['sent'];
                        $spvText = isset($spvResponse['data']['text']) ? $spvResponse['data']['text'] : '';
                        logActivity('Oblio: e-Factura SPV for invoice #' . $invoiceId . ': ' . ($spvSent ? 'sent' : 'not sent') . ' - ' . $spvText);
                    } catch (\Exception $spvEx) {
                        logActivity('Oblio: Failed to send e-Factura to SPV for invoice #' . $invoiceId . ': ' . $spvEx->getMessage());
                    }
                }

                return;
            }
        }

        // Standard document creation (full payload)
        $vatPercentage = !empty($settings['vat_percentage']) ? (int)$settings['vat_percentage'] : 19;

        $payload = WhmcsHelper::buildDocumentPayload(
            $invoiceId,
            $settings['company_cif'],
            $seriesName,
            $settings['cui_field'] ?? '',
            $settings['doc_language'] ?? 'RO'
        );

        // Apply configured VAT percentage
        foreach ($payload['products'] as &$product) {
            $product['vatPercentage'] = $vatPercentage;
        }
        unset($product);

        $response = $api->createDocument($docType, $payload);

        $oblioSeries = isset($response['data']['seriesName']) ? $response['data']['seriesName'] : $seriesName;
        $oblioNumber = isset($response['data']['number']) ? $response['data']['number'] : '';

        WhmcsHelper::logSync($invoiceId, $docType, $oblioSeries, $oblioNumber, 'success');

        logActivity('Oblio: ' . ucfirst($docType) . ' created for invoice #' . $invoiceId . ': ' . $oblioSeries . ' #' . $oblioNumber);

        // Auto-send to SPV (e-Factura) if enabled and this is an invoice
        if ($docType === 'invoice' && !empty($settings['enable_spv']) && $settings['enable_spv'] === 'on'
            && !empty($oblioSeries) && !empty($oblioNumber)) {
            try {
                $spvResponse = $api->sendToSPV($settings['company_cif'], $oblioSeries, $oblioNumber);
                $spvSent = isset($spvResponse['data']['sent']) && $spvResponse['data']['sent'];
                $spvText = isset($spvResponse['data']['text']) ? $spvResponse['data']['text'] : '';
                logActivity('Oblio: e-Factura SPV for invoice #' . $invoiceId . ': ' . ($spvSent ? 'sent' : 'not sent') . ' - ' . $spvText);
            } catch (\Exception $spvEx) {
                logActivity('Oblio: Failed to send e-Factura to SPV for invoice #' . $invoiceId . ': ' . $spvEx->getMessage());
            }
        }
    } catch (\Exception $e) {
        WhmcsHelper::logSync($invoiceId, $docType, '', '', 'error', $e->getMessage());
        logActivity('Oblio: Failed to create ' . $docType . ' for invoice #' . $invoiceId . ': ' . $e->getMessage());
    }
}

/**
 * Hook: InvoiceCreated
 *
 * Triggered when a new invoice is created in WHMCS.
 * Creates a proforma in Oblio when proforma sync is enabled.
 *
 * In the Romanian flow, when a user orders a service, WHMCS creates an
 * unpaid invoice (proforma). This hook sends that proforma to Oblio.
 */
add_hook('InvoiceCreated', 1, function ($vars) {
    $invoiceId = $vars['invoiceid'];

    $settings = oblio_get_module_settings();

    // Check if proforma sync is enabled
    if (empty($settings['enable_proforma']) || $settings['enable_proforma'] !== 'on') {
        return;
    }

    // Verify the invoice is unpaid (proforma) and has line items before syncing.
    // Skip credit notes or already-paid invoices.
    $invoice = WhmcsHelper::getInvoice($invoiceId);
    if (empty($invoice) || $invoice['status'] !== 'Unpaid') {
        return;
    }
    if (empty($invoice['items']['item'])) {
        return;
    }

    oblio_send_document($invoiceId, 'proforma', $settings);
});

/**
 * Hook: InvoicePaid
 *
 * Triggered when an invoice is paid in WHMCS.
 * Creates an invoice in Oblio - CRITICAL for Romanian e-Factura compliance.
 *
 * Per Romanian regulations, when the user pays and the invoice becomes final,
 * it MUST be sent to Oblio for e-Factura submission. This applies regardless
 * of the end user's country.
 */
add_hook('InvoicePaid', 1, function ($vars) {
    $invoiceId = $vars['invoiceid'];

    $settings = oblio_get_module_settings();

    // Check if invoice sync is enabled
    if (empty($settings['enable_invoice']) || $settings['enable_invoice'] !== 'on') {
        return;
    }

    // Always create invoice in Oblio regardless of client country (e-Factura requirement)
    oblio_send_document($invoiceId, 'invoice', $settings);
});
