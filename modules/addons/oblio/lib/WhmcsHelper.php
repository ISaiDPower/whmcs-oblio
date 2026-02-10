<?php

namespace WHMCS\Module\Addon\Oblio;

/**
 * Helper class for extracting WHMCS invoice/client data
 * and transforming it into Oblio API format.
 */
class WhmcsHelper
{
    /**
     * Build the Oblio document payload from a WHMCS invoice.
     *
     * @param int    $invoiceId     WHMCS invoice ID
     * @param string $companyCif    Company CIF configured in module settings
     * @param string $seriesName    Document series name
     * @param string $cuiFieldId    Custom field ID that holds the client CUI/CIF
     * @param string $docLanguage   Document language (default: RO)
     * @return array Oblio document payload
     * @throws \Exception
     */
    public static function buildDocumentPayload($invoiceId, $companyCif, $seriesName, $cuiFieldId = '', $docLanguage = 'RO')
    {
        $invoice = self::getInvoice($invoiceId);
        if (empty($invoice)) {
            throw new \Exception('Invoice #' . $invoiceId . ' not found in WHMCS.');
        }

        $client = self::getClient($invoice['userid']);
        if (empty($client)) {
            throw new \Exception('Client #' . $invoice['userid'] . ' not found in WHMCS.');
        }

        $clientCui = '';
        if (!empty($cuiFieldId)) {
            $clientCui = self::getCustomFieldValue($client['id'], $cuiFieldId);
        }

        $clientData = [
            'name'         => trim($client['companyname'] ?: ($client['firstname'] . ' ' . $client['lastname'])),
            'cif'          => $clientCui,
            'code'         => (string)$client['id'],
            'address'      => trim($client['address1'] . ' ' . $client['address2']),
            'state'        => $client['state'],
            'city'         => $client['city'],
            'country'      => $client['country'],
            'email'        => $client['email'],
            'phone'        => $client['phonenumber'],
            'contact'      => trim($client['firstname'] . ' ' . $client['lastname']),
            'vatPayer'     => !empty($clientCui) ? 1 : 0,
            'save'         => 0,
        ];

        $products = [];
        if (!empty($invoice['items']['item'])) {
            foreach ($invoice['items']['item'] as $item) {
                if ((float)$item['amount'] == 0) {
                    continue;
                }
                $products[] = [
                    'name'            => $item['description'],
                    'code'            => '',
                    'description'     => '',
                    'price'           => (float)$item['amount'],
                    'measuringUnit'   => 'buc',
                    'currency'        => $invoice['currencycode'],
                    'vatName'         => 'Normala',
                    'vatPercentage'   => 0,  // Overridden by module's configured VAT %
                    'vatIncluded'     => 1,
                    'quantity'        => 1,
                    'productType'     => 'Serviciu',
                    'save'            => 0,
                ];
            }
        }

        if (empty($products)) {
            throw new \Exception('Invoice #' . $invoiceId . ' has no line items.');
        }

        $payload = [
            'cif'            => $companyCif,
            'client'         => $clientData,
            'issueDate'      => date('Y-m-d', strtotime($invoice['date'])),
            'dueDate'        => date('Y-m-d', strtotime($invoice['duedate'])),
            'seriesName'     => $seriesName,
            'language'       => $docLanguage,
            'precision'      => 2,
            'currency'       => $invoice['currencycode'],
            'products'       => $products,
            'mentions'       => 'WHMCS Invoice #' . $invoiceId,
            'useStock'       => 0,
        ];

        return $payload;
    }

    /**
     * Get a WHMCS invoice using the local API.
     *
     * @param int $invoiceId
     * @return array
     */
    public static function getInvoice($invoiceId)
    {
        $result = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        if ($result['result'] !== 'success') {
            return [];
        }
        return $result;
    }

    /**
     * Get a WHMCS client using the local API.
     *
     * @param int $clientId
     * @return array
     */
    public static function getClient($clientId)
    {
        $result = localAPI('GetClientsDetails', ['clientid' => $clientId, 'stats' => false]);
        if ($result['result'] !== 'success') {
            return [];
        }
        return $result;
    }

    /**
     * Get a custom field value for a specific client.
     *
     * @param int    $clientId
     * @param string $fieldId  The custom field ID
     * @return string
     */
    public static function getCustomFieldValue($clientId, $fieldId)
    {
        $result = localAPI('GetClientsDetails', ['clientid' => $clientId, 'stats' => false]);
        if ($result['result'] !== 'success' || empty($result['customfields'])) {
            return '';
        }

        foreach ($result['customfields'] as $field) {
            if ((string)$field['id'] === (string)$fieldId) {
                return $field['value'];
            }
        }

        return '';
    }

    /**
     * Get all custom client fields from WHMCS.
     *
     * @return array Array of ['id' => ..., 'fieldname' => ...]
     */
    public static function getCustomClientFields()
    {
        try {
            if (class_exists('\\WHMCS\\Database\\Capsule')) {
                return \WHMCS\Database\Capsule::table('tblcustomfields')
                    ->where('type', 'client')
                    ->select(['id', 'fieldname'])
                    ->get()
                    ->map(function ($row) {
                        return ['id' => $row->id, 'fieldname' => $row->fieldname];
                    })
                    ->toArray();
            }
        } catch (\Exception $e) {
            // Fall through to empty
        }
        return [];
    }

    /**
     * Log an Oblio sync event to the module table.
     *
     * @param int    $invoiceId   WHMCS invoice ID
     * @param string $oblioType   'proforma' or 'invoice'
     * @param string $oblioSeries Document series in Oblio
     * @param string $oblioNumber Document number in Oblio
     * @param string $status      'success' or 'error'
     * @param string $message     Additional message/error
     */
    public static function logSync($invoiceId, $oblioType, $oblioSeries, $oblioNumber, $status, $message = '')
    {
        try {
            if (class_exists('\\WHMCS\\Database\\Capsule')) {
                \WHMCS\Database\Capsule::table('mod_oblio_invoices')->insert([
                    'invoice_id'   => $invoiceId,
                    'oblio_type'   => $oblioType,
                    'oblio_series' => $oblioSeries,
                    'oblio_number' => $oblioNumber,
                    'status'       => $status,
                    'message'      => mb_substr($message, 0, 500),
                    'created_at'   => date('Y-m-d H:i:s'),
                ]); // Message truncated to 500 chars to fit the database text column safely
            }
        } catch (\Exception $e) {
            logActivity('Oblio: Failed to log sync: ' . $e->getMessage());
        }
    }

    /**
     * Check if an invoice has already been synced as a specific document type.
     *
     * @param int    $invoiceId
     * @param string $oblioType 'proforma' or 'invoice'
     * @return bool
     */
    public static function isSynced($invoiceId, $oblioType)
    {
        try {
            if (class_exists('\\WHMCS\\Database\\Capsule')) {
                return \WHMCS\Database\Capsule::table('mod_oblio_invoices')
                    ->where('invoice_id', $invoiceId)
                    ->where('oblio_type', $oblioType)
                    ->where('status', 'success')
                    ->exists();
            }
        } catch (\Exception $e) {
            // Fall through
        }
        return false;
    }

    /**
     * Get the synced proforma record for an invoice, if one exists.
     * Used to create an invoice referencing the original proforma.
     *
     * @param int $invoiceId
     * @return object|null The proforma sync record or null
     */
    public static function getSyncedProforma($invoiceId)
    {
        try {
            if (class_exists('\\WHMCS\\Database\\Capsule')) {
                return \WHMCS\Database\Capsule::table('mod_oblio_invoices')
                    ->where('invoice_id', $invoiceId)
                    ->where('oblio_type', 'proforma')
                    ->where('status', 'success')
                    ->first();
            }
        } catch (\Exception $e) {
            // Fall through
        }
        return null;
    }
}
