<?php

/**
 * Oblio Integration Module for WHMCS
 *
 * Integrates WHMCS invoicing with Oblio.eu accounting platform.
 * Automatically creates proforma invoices and invoices in Oblio
 * when they are created/paid in WHMCS, supporting Romanian e-Factura regulations.
 *
 * @see https://www.oblio.eu/api
 * @see https://developers.whmcs.com/addon-modules/
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Module\Addon\Oblio\OblioApi;
use WHMCS\Module\Addon\Oblio\WhmcsHelper;

require_once __DIR__ . '/lib/OblioApi.php';
require_once __DIR__ . '/lib/WhmcsHelper.php';

/**
 * Module configuration.
 *
 * Defines the module metadata and configuration fields shown in
 * Setup > Addon Modules in the WHMCS admin area.
 *
 * @return array
 */
function oblio_config()
{
    // Build dropdown options for custom client fields (CUI/CIF selection)
    $customFieldOptions = ['' => '-- None --'];
    try {
        $fields = WhmcsHelper::getCustomClientFields();
        foreach ($fields as $field) {
            $customFieldOptions[$field['id']] = $field['fieldname'];
        }
    } catch (\Exception $e) {
        // Silently ignore - fields may not be available during initial setup
    }

    return [
        'name'        => 'Oblio Integration',
        'description' => 'Integrates WHMCS with Oblio.eu for automated invoice and proforma creation. Supports Romanian e-Factura regulations.',
        'version'     => '1.0.0',
        'author'      => 'CROCKY SRL',
        'language'    => 'english',
        'fields'      => [
            'api_email' => [
                'FriendlyName' => 'API Email (Client ID)',
                'Type'         => 'text',
                'Size'         => '60',
                'Description'  => 'Your Oblio API email address used as the client ID.',
            ],
            'api_secret' => [
                'FriendlyName' => 'API Secret',
                'Type'         => 'password',
                'Size'         => '60',
                'Description'  => 'Your Oblio API secret key.',
            ],
            'company_cif' => [
                'FriendlyName' => 'Company CIF',
                'Type'         => 'text',
                'Size'         => '20',
                'Description'  => 'Your company CIF/CUI as registered in Oblio.',
            ],
            'invoice_series' => [
                'FriendlyName' => 'Invoice Series',
                'Type'         => 'text',
                'Size'         => '20',
                'Description'  => 'Series name used for invoices in Oblio (e.g., FCT).',
            ],
            'proforma_series' => [
                'FriendlyName' => 'Proforma Series',
                'Type'         => 'text',
                'Size'         => '20',
                'Description'  => 'Series name used for proforma invoices in Oblio (e.g., PFT).',
            ],
            'cui_field' => [
                'FriendlyName' => 'CUI/CIF Client Field',
                'Type'         => 'dropdown',
                'Options'      => $customFieldOptions,
                'Description'  => 'Select the custom client profile field that stores the CUI/CIF (tax ID).',
            ],
            'doc_language' => [
                'FriendlyName' => 'Document Language',
                'Type'         => 'dropdown',
                'Options'      => [
                    'RO' => 'Romanian',
                    'EN' => 'English',
                    'FR' => 'French',
                    'DE' => 'German',
                ],
                'Default'      => 'RO',
                'Description'  => 'Language for documents created in Oblio.',
            ],
            'enable_proforma' => [
                'FriendlyName' => 'Enable Proforma Sync',
                'Type'         => 'yesno',
                'Description'  => 'Automatically create proforma in Oblio when an unpaid invoice is generated.',
            ],
            'enable_invoice' => [
                'FriendlyName' => 'Enable Invoice Sync',
                'Type'         => 'yesno',
                'Description'  => 'Automatically create invoice in Oblio when an invoice is paid (required for e-Factura).',
            ],
            'vat_percentage' => [
                'FriendlyName' => 'Default VAT %',
                'Type'         => 'text',
                'Size'         => '5',
                'Default'      => '19',
                'Description'  => 'Default VAT percentage for line items (e.g., 19 for Romania).',
            ],
            'enable_spv' => [
                'FriendlyName' => 'Auto-send e-Factura to SPV',
                'Type'         => 'yesno',
                'Description'  => 'Automatically send invoices to SPV (e-Factura) after creation in Oblio.',
            ],
        ],
    ];
}

/**
 * Module activation.
 *
 * Called when the addon module is activated in WHMCS.
 * Creates the required database tables.
 *
 * @return array
 */
function oblio_activate()
{
    try {
        if (!class_exists('\\WHMCS\\Database\\Capsule')) {
            return ['status' => 'error', 'description' => 'Database Capsule not available.'];
        }

        $schema = \WHMCS\Database\Capsule::schema();

        if (!$schema->hasTable('mod_oblio_invoices')) {
            $schema->create('mod_oblio_invoices', function ($table) {
                $table->increments('id');
                $table->integer('invoice_id')->unsigned();
                $table->string('oblio_type', 20);       // 'proforma' or 'invoice'
                $table->string('oblio_series', 50)->default('');
                $table->string('oblio_number', 50)->default('');
                $table->string('status', 20)->default(''); // 'success' or 'error'
                $table->text('message')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index('invoice_id');
                $table->index(['invoice_id', 'oblio_type']);
            });
        }

        return ['status' => 'success', 'description' => 'Oblio module activated successfully.'];
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

/**
 * Module deactivation.
 *
 * Called when the addon module is deactivated in WHMCS.
 * Drops the module database tables.
 *
 * @return array
 */
function oblio_deactivate()
{
    try {
        if (class_exists('\\WHMCS\\Database\\Capsule')) {
            \WHMCS\Database\Capsule::schema()->dropIfExists('mod_oblio_invoices');
        }
        return ['status' => 'success', 'description' => 'Oblio module deactivated successfully.'];
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => 'Deactivation failed: ' . $e->getMessage()];
    }
}

/**
 * Module upgrade.
 *
 * Called when the module version is updated.
 *
 * @param array $vars Module configuration variables including 'version'
 */
function oblio_upgrade($vars)
{
    $currentVersion = $vars['version'];

    // Future upgrade logic goes here
    // Example:
    // if (version_compare($currentVersion, '1.1.0', '<')) {
    //     // Upgrade from < 1.1.0
    // }
}

/**
 * Admin area output.
 *
 * Called when the addon module page is accessed in the WHMCS admin area.
 * Renders the admin interface with sync logs and manual sync options.
 *
 * @param array $vars Module configuration variables
 */
function oblio_output($vars)
{
    $moduleLink = $vars['modulelink'];
    $apiEmail   = $vars['api_email'];
    $apiSecret  = $vars['api_secret'];
    $companyCif = $vars['company_cif'];

    // Handle manual sync action (validate CSRF token for POST requests)
    if (isset($_POST['action'])) {
        $tokenValid = false;
        if (!empty($_SESSION['oblio_csrf_token']) && !empty($_POST['csrf_token'])
            && hash_equals($_SESSION['oblio_csrf_token'], $_POST['csrf_token'])) {
            $tokenValid = true;
        }

        if (!$tokenValid) {
            echo '<div class="alert alert-danger">Invalid or expired CSRF token. Please try again.</div>';
        } elseif ($_POST['action'] === 'sync_invoice' && !empty($_POST['invoice_id'])) {
            $result = oblio_manual_sync((int)$_POST['invoice_id'], $_POST['doc_type'], $vars);
            if ($result['success']) {
                echo '<div class="alert alert-success">' . htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') . '</div>';
            } else {
                echo '<div class="alert alert-danger">' . htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') . '</div>';
            }
        } elseif ($_POST['action'] === 'test_connection') {
            $result = oblio_test_connection($vars);
            if ($result['success']) {
                echo '<div class="alert alert-success">' . htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') . '</div>';
            } else {
                echo '<div class="alert alert-danger">' . htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') . '</div>';
            }
        }
    }

    // Admin page HTML
    echo '<h2>Oblio Integration</h2>';

    // Generate CSRF token for forms
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['oblio_csrf_token'] = $csrfToken;

    // Connection test
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">API Connection</h3></div>';
    echo '<div class="panel-body">';
    echo '<form method="post" action="' . htmlspecialchars($moduleLink, ENT_QUOTES, 'UTF-8') . '">';
    echo '<input type="hidden" name="action" value="test_connection">';
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">';
    echo '<p>Test the connection to the Oblio API with your configured credentials.</p>';
    echo '<button type="submit" class="btn btn-info">Test Connection</button>';
    echo '</form>';
    echo '</div></div>';

    // Manual sync form
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Manual Sync</h3></div>';
    echo '<div class="panel-body">';
    echo '<form method="post" action="' . htmlspecialchars($moduleLink, ENT_QUOTES, 'UTF-8') . '" class="form-inline">';
    echo '<input type="hidden" name="action" value="sync_invoice">';
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">';
    echo '<div class="form-group" style="margin-right:10px;">';
    echo '<label for="invoice_id" style="margin-right:5px;">Invoice ID:</label>';
    echo '<input type="number" name="invoice_id" id="invoice_id" class="form-control" required>';
    echo '</div>';
    echo '<div class="form-group" style="margin-right:10px;">';
    echo '<label for="doc_type" style="margin-right:5px;">Type:</label>';
    echo '<select name="doc_type" id="doc_type" class="form-control">';
    echo '<option value="proforma">Proforma</option>';
    echo '<option value="invoice">Invoice</option>';
    echo '</select>';
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary">Sync to Oblio</button>';
    echo '</form>';
    echo '</div></div>';

    // Recent sync log
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Recent Sync Log</h3></div>';
    echo '<div class="panel-body">';

    try {
        if (class_exists('\\WHMCS\\Database\\Capsule')) {
            $logs = \WHMCS\Database\Capsule::table('mod_oblio_invoices')
                ->orderBy('created_at', 'desc')
                ->limit(50)
                ->get();

            if ($logs->count() > 0) {
                echo '<table class="table table-striped table-bordered">';
                echo '<thead><tr>';
                echo '<th>Date</th><th>Invoice ID</th><th>Type</th><th>Series</th><th>Number</th><th>Status</th><th>Message</th>';
                echo '</tr></thead><tbody>';

                foreach ($logs as $log) {
                    $statusClass = $log->status === 'success' ? 'success' : 'danger';
                    echo '<tr class="' . $statusClass . '">';
                    echo '<td>' . htmlspecialchars($log->created_at, ENT_QUOTES, 'UTF-8') . '</td>';
                    echo '<td>' . (int)$log->invoice_id . '</td>';
                    echo '<td>' . htmlspecialchars($log->oblio_type, ENT_QUOTES, 'UTF-8') . '</td>';
                    echo '<td>' . htmlspecialchars($log->oblio_series, ENT_QUOTES, 'UTF-8') . '</td>';
                    echo '<td>' . htmlspecialchars($log->oblio_number, ENT_QUOTES, 'UTF-8') . '</td>';
                    echo '<td><span class="label label-' . $statusClass . '">' . htmlspecialchars($log->status, ENT_QUOTES, 'UTF-8') . '</span></td>';
                    echo '<td>' . htmlspecialchars($log->message, ENT_QUOTES, 'UTF-8') . '</td>';
                    echo '</tr>';
                }

                echo '</tbody></table>';
            } else {
                echo '<p>No sync records found.</p>';
            }
        }
    } catch (\Exception $e) {
        echo '<p class="text-danger">Error loading sync log: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    }

    echo '</div></div>';
}

/**
 * Client area output.
 *
 * @param array $vars Module configuration variables
 * @return array
 */
function oblio_clientarea($vars)
{
    return [
        'pagetitle'    => 'Oblio Invoices',
        'breadcrumb'   => ['index.php?m=oblio' => 'Oblio Invoices'],
        'templatefile' => '',
        'vars'         => [],
    ];
}

/**
 * Test the Oblio API connection with configured credentials.
 *
 * @param array $vars Module configuration variables
 * @return array ['success' => bool, 'message' => string]
 */
function oblio_test_connection($vars)
{
    try {
        if (empty($vars['api_email']) || empty($vars['api_secret'])) {
            return ['success' => false, 'message' => 'API email and secret must be configured.'];
        }

        $api = new OblioApi($vars['api_email'], $vars['api_secret']);
        $api->authenticate();

        $companies = $api->getCompanies();
        $companyNames = [];
        if (!empty($companies['data'])) {
            foreach ($companies['data'] as $company) {
                $companyNames[] = $company['cif'] . ' - ' . $company['company'];
            }
        }

        $msg = 'Connection successful!';
        if (!empty($companyNames)) {
            $msg .= ' Companies found: ' . implode(', ', $companyNames);
        }

        return ['success' => true, 'message' => $msg];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => 'Connection failed: ' . $e->getMessage()];
    }
}

/**
 * Manually sync a WHMCS invoice to Oblio.
 *
 * @param int    $invoiceId WHMCS invoice ID
 * @param string $docType   'proforma' or 'invoice'
 * @param array  $vars      Module configuration variables
 * @return array ['success' => bool, 'message' => string]
 */
function oblio_manual_sync($invoiceId, $docType, $vars)
{
    $docType = in_array($docType, ['proforma', 'invoice']) ? $docType : 'invoice';

    try {
        if (empty($vars['api_email']) || empty($vars['api_secret'])) {
            return ['success' => false, 'message' => 'API credentials not configured.'];
        }
        if (empty($vars['company_cif'])) {
            return ['success' => false, 'message' => 'Company CIF not configured.'];
        }

        $seriesName = ($docType === 'proforma') ? $vars['proforma_series'] : $vars['invoice_series'];
        if (empty($seriesName)) {
            return ['success' => false, 'message' => ucfirst($docType) . ' series not configured.'];
        }

        $vatPercentage = !empty($vars['vat_percentage']) ? (int)$vars['vat_percentage'] : 19;

        $payload = WhmcsHelper::buildDocumentPayload(
            $invoiceId,
            $vars['company_cif'],
            $seriesName,
            $vars['cui_field'] ?? '',
            $vars['doc_language'] ?? 'RO'
        );

        // Apply configured VAT percentage to all products
        foreach ($payload['products'] as &$product) {
            $product['vatPercentage'] = $vatPercentage;
        }
        unset($product);

        $api = new OblioApi($vars['api_email'], $vars['api_secret']);
        $response = $api->createDocument($docType, $payload);

        $oblioSeries = isset($response['data']['seriesName']) ? $response['data']['seriesName'] : $seriesName;
        $oblioNumber = isset($response['data']['number']) ? $response['data']['number'] : '';

        WhmcsHelper::logSync($invoiceId, $docType, $oblioSeries, $oblioNumber, 'success');

        return [
            'success' => true,
            'message' => ucfirst($docType) . ' created in Oblio: ' . $oblioSeries . ' #' . $oblioNumber,
        ];
    } catch (\Exception $e) {
        WhmcsHelper::logSync($invoiceId, $docType, '', '', 'error', $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
