<?php

function receipt_pdf_text(string $text): string
{
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($text === false) {
        $text = '';
    }

    return str_replace(["\\", "(", ")", "\r", "\n"], ["\\\\", "\\(", "\\)", '', ' '], $text);
}

function build_order_receipt_pdf(array $order, array $items): string
{
    $lines = [
        'TAKOYAKI SHOP - TRANSACTION RECEIPT',
        'Order: #' . $order['order_id'],
        'Date: ' . date('M d, Y g:i A', strtotime($order['order_date'])),
        'Customer: ' . ($order['full_name'] ?: 'Customer'),
        'Order type: ' . ucfirst($order['order_type']),
        'Status: ' . $order['status'],
        str_repeat('-', 72),
    ];

    foreach ($items as $item) {
        $label = (int) $item['quantity'] . ' x ' . $item['flavor_name'] . ' (' . $item['size_label'] . ')';
        $lines[] = $label;
        if (!empty($item['addon_names'])) {
            $lines[] = '   Add-ons: ' . $item['addon_names'];
        }
        $unitPrice = (float) $item['item_price'] + (float) $item['addon_total'];
        $lines[] = '   PHP ' . number_format($unitPrice, 2) . ' each | Line total: PHP '
            . number_format($unitPrice * (int) $item['quantity'], 2);
    }

    $lines[] = str_repeat('-', 72);
    $lines[] = 'Subtotal: PHP ' . number_format((float) $order['subtotal'], 2);
    $lines[] = 'Delivery fee: PHP ' . number_format((float) $order['delivery_fee'], 2);
    $lines[] = 'GRAND TOTAL: PHP ' . number_format((float) $order['total_amount'], 2);

    $lineChunks = array_chunk($lines, 48);
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pageReferences = [];

    foreach ($lineChunks as $pageIndex => $pageLines) {
        $pageObject = 4 + ($pageIndex * 2);
        $contentObject = $pageObject + 1;
        $pageReferences[] = $pageObject . ' 0 R';

        $stream = "BT\n/F1 10 Tf\n50 790 Td\n14 TL\n";
        foreach ($pageLines as $line) {
            $stream .= '(' . receipt_pdf_text($line) . ") Tj\nT*\n";
        }
        $stream .= "ET\n";
        $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 3 0 R >> >> /Contents ' . $contentObject . ' 0 R >>';
        $objects[$contentObject] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';
    }

    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageReferences) . '] /Count ' . count($pageReferences) . ' >>';
    ksort($objects);
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    $objectCount = max(array_keys($objects));

    for ($objectNumber = 1; $objectNumber <= $objectCount; $objectNumber++) {
        $offsets[$objectNumber] = strlen($pdf);
        $pdf .= $objectNumber . " 0 obj\n" . $objects[$objectNumber] . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . ($objectCount + 1) . "\n0000000000 65535 f \n";
    for ($objectNumber = 1; $objectNumber <= $objectCount; $objectNumber++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$objectNumber]);
    }
    $pdf .= "trailer\n<< /Size " . ($objectCount + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

    return $pdf;
}
