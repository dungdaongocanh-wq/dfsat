<?php
// VERSION CHECK - xóa sau khi verify
define('EHOADON_CLIENT_VERSION', 'v3-split-invoice-' . date('Ymd'));

/**
 * Bkav eHoadon 2.0 WebService Client - PRODUCTION READY
 *
 * ItemTypeID:
 *   0 = HangHoa  → dòng dịch vụ (CÓ STT)
 *   4 = GhiChu   → dòng diễn giải (KHÔNG STT)
 *
 * TaxRateID:
 *   1 = 0%
 *   2 = 5%
 *   3 = 10%
 *   4 = Không chịu thuế (KCT)
 *   5 = Không kê khai thuế (KKT)
 *   6 = Khác
 *   9 = 8%
 */
class EHoaDonClient
{
    private string $wsUrl;
    private string $partnerGuid;
    private string $aesKey;
    private string $aesIv;

    private const ITEM_HANG_HOA = 0;
    private const ITEM_GHI_CHU  = 4;

    private const TAX_0PCT  = 1;
    private const TAX_5PCT  = 2;
    private const TAX_10PCT = 3;
    private const TAX_KCT   = 4;
    private const TAX_KKT   = 5;
    private const TAX_KHAC  = 6;
    private const TAX_8PCT  = 9;

    private const TAX_RATE_MAP = [
        0  => self::TAX_0PCT,
        5  => self::TAX_5PCT,
        8  => self::TAX_8PCT,
        10 => self::TAX_10PCT,
    ];

    private const PAY_METHOD_MAP = [
        'TM'    => 1,
        'CK'    => 2,
        'TM/CK' => 3,
    ];

    private const PHONE_MAP = [
        '0162' => '032', '0163' => '033', '0164' => '034',
        '0165' => '035', '0166' => '036', '0167' => '037',
        '0168' => '038', '0169' => '039',
        '0123' => '083', '0124' => '084', '0125' => '085',
        '0127' => '081', '0126' => '076', '0128' => '078',
        '0129' => '079', '0120' => '070', '0121' => '079',
        '0122' => '077', '0186' => '056', '0188' => '058',
        '0199' => '059',
    ];

    public function __construct()
    {
        $this->wsUrl       = EHOADON_PRODUCTION ? EHOADON_WS_URL : EHOADON_WS_URL_TEST;
        $this->partnerGuid = EHOADON_PARTNER_GUID;

        $token    = EHOADON_PARTNER_TOKEN;
        $colonPos = strrpos($token, ':');
        if ($colonPos === false) {
            throw new \Exception('PartnerToken sai định dạng. Cần: Base64Key:Base64IV');
        }
        $this->aesKey = base64_decode(substr($token, 0, $colonPos));
        $this->aesIv  = base64_decode(substr($token, $colonPos + 1));

        if (strlen($this->aesKey) !== 32) {
            throw new \Exception('AES Key phải là 32 bytes, hiện tại: ' . strlen($this->aesKey));
        }
        if (strlen($this->aesIv) !== 16) {
            throw new \Exception('AES IV phải là 16 bytes, hiện tại: ' . strlen($this->aesIv));
        }
    }

    // -------------------------------------------------------
    // Public API
    // -------------------------------------------------------
    public function createInvoice(array $invoiceData): array
    {
        $raw = $this->execCommand([
            'CmdType'       => 100,
            'CommandObject' => [$invoiceData],
        ]);
        return $this->unwrapResponse($raw);
    }

    public function getInvoice(int $partnerInvoiceId): array
    {
        $raw = $this->execCommand([
            'CmdType'       => 800,
            'CommandObject' => [['PartnerInvoiceID' => $partnerInvoiceId]],
        ]);
        return $this->unwrapResponse($raw);
    }

    public function downloadPdf(int $partnerInvoiceId): array
    {
        $raw = $this->execCommand([
            'CmdType'       => 808,
            'CommandObject' => [['PartnerInvoiceID' => $partnerInvoiceId]],
        ]);
        return $this->unwrapResponse($raw);
    }

    public function downloadXml(int $partnerInvoiceId): array
    {
        $raw = $this->execCommand([
            'CmdType'       => 809,
            'CommandObject' => [['PartnerInvoiceID' => $partnerInvoiceId]],
        ]);
        return $this->unwrapResponse($raw);
    }

    public function cancelInvoice(int $partnerInvoiceId, string $reason): array
    {
        $raw = $this->execCommand([
            'CmdType'       => 202,
            'CommandObject' => [[
                'PartnerInvoiceID' => $partnerInvoiceId,
                'Reason'           => $reason,
            ]],
        ]);
        return $this->unwrapResponse($raw);
    }

    // -------------------------------------------------------
    // Unwrap response 2 lớp BKAV
    // -------------------------------------------------------
    public function unwrapResponse(array $raw): array
    {
        if (!empty($raw['isError'])) {
            $msg = $raw['Message'] ?? $raw['Description'] ?? json_encode($raw, JSON_UNESCAPED_UNICODE);
            throw new \Exception('eHoaDon system error: ' . $msg);
        }

        if (!isset($raw['Object'])) {
            return $raw;
        }

        $obj = $raw['Object'];

        if (is_string($obj) && $obj !== '') {
            $decoded = json_decode($obj, true);
            if ($decoded !== null) {
                $obj = $decoded;
            }
        }

        if (is_array($obj) && isset($obj[0]) && is_array($obj[0])) {
            $item = $obj[0];
        } elseif (is_array($obj) && !empty($obj)) {
            $item = $obj;
        } else {
            return array_merge($raw, ['_object_raw' => $obj, '_unwrap_failed' => true]);
        }

        $item['_wrapper_status'] = $raw['Status']  ?? null;
        $item['_wrapper_isOk']   = $raw['isOk']    ?? null;
        $item['_wrapper_isErr']  = $raw['isError'] ?? null;

        return $item;
    }

    // -------------------------------------------------------
    // isSuccess
    // -------------------------------------------------------
    public function isSuccess(array $result): bool
    {
        if (empty($result)) {
            return false;
        }

        $guid = $result['InvoiceGUID'] ?? '';
        if (!empty($guid) && $guid !== '00000000-0000-0000-0000-000000000000') {
            return true;
        }

        if (array_key_exists('Status', $result)) {
            return intval($result['Status']) === 0;
        }

        return false;
    }

    // -------------------------------------------------------
    // Lấy message lỗi
    // -------------------------------------------------------
    public function getErrorMessage(array $result): string
    {
        $messLog = trim($result['MessLog'] ?? '');
        if ($messLog !== '' && $messLog !== 'null') {
            return $messLog;
        }
        if (!empty($result['Description'])) return $result['Description'];
        if (!empty($result['Message']))     return $result['Message'];

        $display = array_filter(
            $result,
            fn($k) => !str_starts_with((string)$k, '_'),
            ARRAY_FILTER_USE_KEY
        );
        return json_encode($display, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    // -------------------------------------------------------
    // Chuẩn hóa SĐT
    // -------------------------------------------------------
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (empty($phone)) return '';
        if (strlen($phone) === 11 && str_starts_with($phone, '84')) {
            $phone = '0' . substr($phone, 2);
        }
        if (strlen($phone) === 10 && str_starts_with($phone, '0')) {
            return $phone;
        }
        if (strlen($phone) === 11) {
            $prefix4 = substr($phone, 0, 4);
            if (isset(self::PHONE_MAP[$prefix4])) {
                return self::PHONE_MAP[$prefix4] . substr($phone, 4);
            }
            return '';
        }
        return '';
    }

    // -------------------------------------------------------
    // Dòng diễn giải GhiChu
    // -------------------------------------------------------
    private function buildDescriptionItem(string $text): array
    {
        return [
            'ItemTypeID'        => self::ITEM_GHI_CHU,
            'ItemName'          => $text,
            'UnitName'          => '',
            'Qty'               => 0,
            'Price'             => 0,
            'Amount'            => 0,
            'TaxRateID'         => self::TAX_KKT,
            'TaxAmount'         => 0,
            'IsDiscount'        => false,
            'UserDefineDetails' => '',
        ];
    }

    // -------------------------------------------------------
    // Build Invoice Payload
    // ── invoiceGroup: 'all' | 'vat8' | 'vat0'
    //    vat8 → PartnerInvoiceID = shipment.id        (HĐ có VAT%)
    //    vat0 → PartnerInvoiceID = shipment.id*10+1   (HĐ 0%, tránh trùng)
    // -------------------------------------------------------
    public function buildInvoicePayload(
        array  $shipment,
        array  $sells,
        string $invoiceDate,
        string $buyerName,
        string $paymentMethod = 'TM/CK',
        string $extraNote     = '',
        string $invoiceGroup  = 'all'   // ← THAM SỐ MỚI
    ): array {
        $vatSells = array_values(array_filter($sells, function ($s) {
            return empty($s['is_pob']) || intval($s['is_pob']) !== 1;
        }));

        if (empty($vatSells)) {
            throw new \Exception('Không có khoản nào để xuất HĐ VAT (tất cả là Chi hộ).');
        }

        $payMethodId = self::PAY_METHOD_MAP[strtoupper(trim($paymentMethod))] ?? 3;
        $mobile      = $this->normalizePhone($shipment['customer_phone'] ?? '');
        $hawb        = trim($shipment['hawb']                   ?? '');
        $tkHQ        = trim($shipment['customs_declaration_no'] ?? '');

        if ($hawb !== '' && $tkHQ !== '') {
            $descText = 'SỐ VẬN ĐƠN (' . strtoupper($hawb) . ') | SỐ TỜ KHAI (' . $tkHQ . ')';
        } elseif ($hawb !== '') {
            $descText = 'SỐ VẬN ĐƠN (' . strtoupper($hawb) . ')';
        } elseif ($tkHQ !== '') {
            $descText = 'SỐ TỜ KHAI (' . $tkHQ . ')';
        } else {
            $descText = '';
        }

        $items = [];
        if ($descText !== '') {
            $items[] = $this->buildDescriptionItem($descText);
        }

        foreach ($vatSells as $sell) {
            $vatPct    = intval($sell['vat']);
            $qty       = floatval($sell['quantity']);
            $unitPrice = floatval($sell['unit_price']);
            $amount    = round($unitPrice * $qty, 0);
            $taxRateId = self::TAX_RATE_MAP[$vatPct] ?? self::TAX_10PCT;
            $vatAmt    = round($amount * $vatPct / 100, 0);

            $itemName = $sell['description'];
            if (stripos($sell['code'] ?? '', 'TRUCK') !== false
                && !empty(trim($sell['notes'] ?? ''))) {
                $itemName = $sell['description'] . ' (' . trim($sell['notes']) . ')';
            }

            $items[] = [
                'ItemTypeID'        => self::ITEM_HANG_HOA,
                'ItemName'          => $itemName,
                'UnitName'          => 'Lô',
                'Qty'               => $qty,
                'Price'             => $unitPrice,
                'Amount'            => $amount,
                'TaxRateID'         => $taxRateId,
                'TaxAmount'         => $vatAmt,
                'IsDiscount'        => false,
                'UserDefineDetails' => '',
            ];
        }

        // Ghi chú Note
        $note = 'Phi DV: ' . $shipment['job_no'];
        if ($hawb !== '') $note .= ' | So van don: ' . $hawb;
        if ($tkHQ !== '') $note .= ' | So to khai: ' . $tkHQ;
        if ($extraNote !== '') $note .= ' | ' . $extraNote;

        // ── [KEY FIX] PartnerInvoiceID & PartnerInvoiceStringID phải unique ──
        // HĐ có VAT%  → dùng shipment.id gốc
        // HĐ 0%       → dùng shipment.id * 10 + 1  (luôn khác với id gốc)
        $baseId            = intval($shipment['id']);
        $partnerInvoiceId  = ($invoiceGroup === 'vat0') ? ($baseId * 10 + 1) : $baseId;
        $partnerInvoiceStr = ($invoiceGroup === 'vat0')
            ? ($shipment['job_no'] . '-0PCT')
            : $shipment['job_no'];

        return [
            'Invoice' => [
                'InvoiceTypeID'    => 1,
                'InvoiceDate'      => date('Y-m-d\TH:i:s', strtotime($invoiceDate)),
                'InvoiceNo'        => 0,
                'InvoiceForm'      => '',
                'InvoiceSerial'    => '',
                'InvoiceStatusID'  => 1,
                'SignedDate'       => date('Y-m-d\TH:i:s', strtotime($invoiceDate)),
                'BuyerName'        => $buyerName,
                'BuyerTaxCode'     => $shipment['customer_tax']     ?? '',
                'BuyerUnitName'    => $shipment['company_name']     ?? '',
                'BuyerAddress'     => $shipment['customer_address'] ?? '',
                'BuyerBankAccount' => '',
                'PayMethodID'      => $payMethodId,
                'ReceiveTypeID'    => !empty($mobile) ? 3 : 1,
                'ReceiverEmail'    => $shipment['customer_email']   ?? '',
                'ReceiverMobile'   => $mobile,
                'ReceiverName'     => $buyerName,
                'ReceiverAddress'  => $shipment['customer_address'] ?? '',
                'Note'             => $note,
                'BillCode'         => $partnerInvoiceStr,
                'CurrencyID'       => 'VND',
                'ExchangeRate'     => 1.0,
                'MaCuaCQT'         => '',
                'isBTH'            => 'false',
                'UserDefine'       => '',
            ],
            'ListInvoiceDetailsWS'    => $items,
            'ListInvoiceAttachFileWS' => [],
            'PartnerInvoiceID'        => $partnerInvoiceId,       // ← UNIQUE
            'PartnerInvoiceStringID'  => $partnerInvoiceStr,      // ← UNIQUE
        ];
    }

    // -------------------------------------------------------
    // PRIVATE: SOAP
    // -------------------------------------------------------
    private function execCommand(array $payload): array
    {
        $encrypted = $this->encryptPayload($payload);
        $soapXml   = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            . ' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
            . ' xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body>'
            . '<ExecCommand xmlns="http://tempuri.org/">'
            . '<partnerGUID>' . $this->partnerGuid . '</partnerGUID>'
            . '<CommandData>' . $encrypted . '</CommandData>'
            . '</ExecCommand>'
            . '</soap:Body>'
            . '</soap:Envelope>';

        $ch = curl_init($this->wsUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $soapXml,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: "http://tempuri.org/ExecCommand"',
                'Content-Length: ' . strlen($soapXml),
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \Exception('cURL error: ' . $curlErr);
        }

        // Parse SOAP response
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response);
        if ($xml === false) {
            throw new \Exception('Invalid XML response: ' . substr($response, 0, 300));
        }

        $xml->registerXPathNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xml->registerXPathNamespace('tns',  'http://tempuri.org/');

        $resultNodes = $xml->xpath('//ExecCommandResult');
        if (empty($resultNodes)) {
            throw new \Exception('Không tìm thấy ExecCommandResult trong response.');
        }

        $resultStr = (string)$resultNodes[0];
        $decrypted = $this->decryptPayload($resultStr);
        $decoded   = json_decode($decrypted, true);

        if ($decoded === null) {
            throw new \Exception('Không parse được JSON từ eHoaDon: ' . substr($decrypted, 0, 300));
        }

        return $decoded;
    }

    private function encryptPayload(array $data): string
    {
        $json      = json_encode($data, JSON_UNESCAPED_UNICODE);
        $encrypted = openssl_encrypt($json, 'AES-256-CBC', $this->aesKey, OPENSSL_RAW_DATA, $this->aesIv);
        return base64_encode($encrypted);
    }

    private function decryptPayload(string $data): string
    {
        $decoded   = base64_decode($data);
        $decrypted = openssl_decrypt($decoded, 'AES-256-CBC', $this->aesKey, OPENSSL_RAW_DATA, $this->aesIv);
        if ($decrypted === false) {
            throw new \Exception('Giải mã AES thất bại.');
        }
        return $decrypted;
    }
}