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
            'phone'        => self::formatPhoneWithPrefix($client['phonenumber'], $client['country']),
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
                    'price'           => round((float)$item['amount'], 2),
                    'measuringUnit'   => 'buc',
                    'currency'        => $invoice['currencycode'],
                    'vatName'         => 'Normala',
                    'vatPercentage'   => 0,  // Overridden by module's configured VAT %
                    'vatIncluded'     => 0,
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

    /**
     * Format a phone number with country dial prefix.
     *
     * If the phone number already starts with '+' or '00', it is returned as-is.
     * Otherwise, the country dial code is prepended based on the ISO 2-letter country code.
     *
     * @param string $phone   Raw phone number
     * @param string $country ISO 3166-1 alpha-2 country code (e.g., 'RO', 'DE')
     * @return string Phone number with country prefix
     */
    public static function formatPhoneWithPrefix($phone, $country)
    {
        $phone = trim($phone);
        if (empty($phone)) {
            return '';
        }

        // Already has international prefix
        if (strpos($phone, '+') === 0 || strpos($phone, '00') === 0) {
            return $phone;
        }

        $prefix = self::getCountryDialCode($country);
        if (!empty($prefix)) {
            // Strip single leading zero from local numbers before adding prefix
            if (substr($phone, 0, 1) === '0') {
                $phone = substr($phone, 1);
            }
            return $prefix . $phone;
        }

        return $phone;
    }

    /**
     * Get the dial code for a given ISO 2-letter country code.
     *
     * @param string $countryCode ISO 3166-1 alpha-2 country code
     * @return string Dial code with '+' prefix, or empty string if unknown
     */
    public static function getCountryDialCode($countryCode)
    {
        $codes = [
            'AD' => '+376', 'AE' => '+971', 'AF' => '+93',  'AG' => '+1',
            'AL' => '+355', 'AM' => '+374', 'AO' => '+244', 'AR' => '+54',
            'AT' => '+43',  'AU' => '+61',  'AZ' => '+994', 'BA' => '+387',
            'BB' => '+1',   'BD' => '+880', 'BE' => '+32',  'BF' => '+226',
            'BG' => '+359', 'BH' => '+973', 'BI' => '+257', 'BJ' => '+229',
            'BN' => '+673', 'BO' => '+591', 'BR' => '+55',  'BS' => '+1',
            'BT' => '+975', 'BW' => '+267', 'BY' => '+375', 'BZ' => '+501',
            'CA' => '+1',   'CD' => '+243', 'CF' => '+236', 'CG' => '+242',
            'CH' => '+41',  'CI' => '+225', 'CL' => '+56',  'CM' => '+237',
            'CN' => '+86',  'CO' => '+57',  'CR' => '+506', 'CU' => '+53',
            'CV' => '+238', 'CY' => '+357', 'CZ' => '+420', 'DE' => '+49',
            'DJ' => '+253', 'DK' => '+45',  'DM' => '+1',   'DO' => '+1',
            'DZ' => '+213', 'EC' => '+593', 'EE' => '+372', 'EG' => '+20',
            'ER' => '+291', 'ES' => '+34',  'ET' => '+251', 'FI' => '+358',
            'FJ' => '+679', 'FR' => '+33',  'GA' => '+241', 'GB' => '+44',
            'GD' => '+1',   'GE' => '+995', 'GH' => '+233', 'GM' => '+220',
            'GN' => '+224', 'GQ' => '+240', 'GR' => '+30',  'GT' => '+502',
            'GW' => '+245', 'GY' => '+592', 'HK' => '+852', 'HN' => '+504',
            'HR' => '+385', 'HT' => '+509', 'HU' => '+36',  'ID' => '+62',
            'IE' => '+353', 'IL' => '+972', 'IN' => '+91',   'IQ' => '+964',
            'IR' => '+98',  'IS' => '+354', 'IT' => '+39',   'JM' => '+1',
            'JO' => '+962', 'JP' => '+81',  'KE' => '+254',  'KG' => '+996',
            'KH' => '+855', 'KI' => '+686', 'KM' => '+269',  'KN' => '+1',
            'KP' => '+850', 'KR' => '+82',  'KW' => '+965',  'KZ' => '+7',
            'LA' => '+856', 'LB' => '+961', 'LC' => '+1',    'LI' => '+423',
            'LK' => '+94',  'LR' => '+231', 'LS' => '+266',  'LT' => '+370',
            'LU' => '+352', 'LV' => '+371', 'LY' => '+218',  'MA' => '+212',
            'MC' => '+377', 'MD' => '+373', 'ME' => '+382',  'MG' => '+261',
            'MK' => '+389', 'ML' => '+223', 'MM' => '+95',   'MN' => '+976',
            'MO' => '+853', 'MR' => '+222', 'MT' => '+356',  'MU' => '+230',
            'MV' => '+960', 'MW' => '+265', 'MX' => '+52',   'MY' => '+60',
            'MZ' => '+258', 'NA' => '+264', 'NE' => '+227',  'NG' => '+234',
            'NI' => '+505', 'NL' => '+31',  'NO' => '+47',   'NP' => '+977',
            'NR' => '+674', 'NZ' => '+64',  'OM' => '+968',  'PA' => '+507',
            'PE' => '+51',  'PG' => '+675', 'PH' => '+63',   'PK' => '+92',
            'PL' => '+48',  'PT' => '+351', 'PY' => '+595',  'QA' => '+974',
            'RO' => '+40',  'RS' => '+381', 'RU' => '+7',    'RW' => '+250',
            'SA' => '+966', 'SB' => '+677', 'SC' => '+248',  'SD' => '+249',
            'SE' => '+46',  'SG' => '+65',  'SI' => '+386',  'SK' => '+421',
            'SL' => '+232', 'SM' => '+378', 'SN' => '+221',  'SO' => '+252',
            'SR' => '+597', 'SS' => '+211', 'ST' => '+239',  'SV' => '+503',
            'SY' => '+963', 'SZ' => '+268', 'TD' => '+235',  'TG' => '+228',
            'TH' => '+66',  'TJ' => '+992', 'TL' => '+670',  'TM' => '+993',
            'TN' => '+216', 'TO' => '+676', 'TR' => '+90',   'TT' => '+1',
            'TV' => '+688', 'TW' => '+886', 'TZ' => '+255',  'UA' => '+380',
            'UG' => '+256', 'US' => '+1',   'UY' => '+598',  'UZ' => '+998',
            'VA' => '+379', 'VC' => '+1',   'VE' => '+58',   'VN' => '+84',
            'VU' => '+678', 'WS' => '+685', 'XK' => '+383',  'YE' => '+967',
            'ZA' => '+27',  'ZM' => '+260', 'ZW' => '+263',
        ];

        $countryCode = strtoupper(trim($countryCode));
        return isset($codes[$countryCode]) ? $codes[$countryCode] : '';
    }
}
