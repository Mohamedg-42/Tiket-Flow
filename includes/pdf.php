<?php
// ==============================================================================
// GÉNÉRATEUR PDF NATIF SANS BIBLIOTHÈQUE (includes/pdf.php)
// Construit un document PDF valide (billets + QR Codes) à partir du noyau PHP.
// - Texte : polices de base Helvetica / Helvetica-Bold (encodage CP1252)
// - Images : QR Codes téléchargés puis convertis en JPEG via GD (DCTDecode)
// ==============================================================================

if (!function_exists('pdf_escape_text')) {
    /**
     * Échappe une chaîne pour un flux de contenu PDF (parenthèses / antislash)
     */
    function pdf_escape_text(string $s): string
    {
        $s = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
        // Les polices de base utilisent CP1252 : conversion depuis UTF-8
        $conv = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
        return $conv === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $conv;
    }
}

if (!function_exists('pdf_jpeg_size')) {
    /**
     * Extrait les dimensions d'un flux JPEG (marqueurs SOF0/SOF1/SOF2)
     * @return array{0:int,1:int} [largeur, hauteur]
     */
    function pdf_jpeg_size(string $jpeg): array
    {
        $len = strlen($jpeg);
        $i = 2;
        while ($i + 9 < $len) {
            if ($jpeg[$i] !== "\xFF") { $i++; continue; }
            $marker = ord($jpeg[$i + 1]);
            // SOF0, SOF1, SOF2 (progressif), SOF3
            if ($marker >= 0xC0 && $marker <= 0xC3) {
                return [ord($jpeg[$i + 7]) << 8 | ord($jpeg[$i + 8]), ord($jpeg[$i + 5]) << 8 | ord($jpeg[$i + 6])];
            }
            if ($marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD9)) { $i += 2; continue; }
            $i += 2 + (ord($jpeg[$i + 2]) << 8 | ord($jpeg[$i + 3]));
        }
        return [250, 250];
    }
}

if (!function_exists('pdf_fetch_qr_jpeg')) {
    /**
     * Télécharge le QR Code et le convertit en JPEG (via GD si nécessaire).
     * @return array{data:string,w:int,h:int}|null
     */
    function pdf_fetch_qr_jpeg(string $url): ?array
    {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'TicketFlow/1.0']]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false || strlen($raw) < 100) {
            return null;
        }

        // Déjà un JPEG : utilisé tel quel
        if (substr($raw, 0, 3) === "\xFF\xD8\xFF") {
            [$w, $h] = pdf_jpeg_size($raw);
            return ['data' => $raw, 'w' => $w, 'h' => $h];
        }

        // Sinon (PNG) : conversion JPEG via GD, sur fond blanc opaque
        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring($raw);
            if ($img !== false) {
                $w = imagesx($img);
                $h = imagesy($img);
                $white = imagecreatetruecolor($w, $h);
                $bg = imagecolorallocate($white, 255, 255, 255);
                imagefilledrectangle($white, 0, 0, $w, $h, $bg);
                imagecopy($white, $img, 0, 0, 0, 0, $w, $h);
                ob_start();
                imagejpeg($white, null, 85);
                $jpeg = ob_get_clean();
                imagedestroy($white);
                imagedestroy($img);
                if (strlen($jpeg) > 100) {
                    return ['data' => $jpeg, 'w' => $w, 'h' => $h];
                }
            }
        }
        return null;
    }
}
if (!function_exists('generateTicketsPdf')) {
    /**
     * Génère le PDF contenant tous les billets de la commande
     *
     * @param array  $tickets      Billets : code_unique, qr_code, event_name, type_ticket, place, prix, date_ev, heure, lieu
     * @param string $orderNumber  Numéro de commande
     * @param string $clientName   Nom du client
     * @return string              Binaire PDF (chaîne vide si aucun billet)
     */
    function generateTicketsPdf(array $tickets, string $orderNumber, string $clientName): string
    {
        if (empty($tickets)) {
            return '';
        }

        $W = 595.28;  // A4 en points
        $H = 841.89;

        $ops_per_page = [];
        $images = [];    // [['data'=>..,'w'=>..,'h'=>..], ...]
        $img_names = [];

        $rect = function (float $x, float $y, float $w, float $h, string $rgb) {
            return sprintf("%s rg %.2f %.2f %.2f %.2f re f", $rgb, $x, $y, $w, $h);
        };
        $text = function (string $str, float $x, float $y, float $size, string $font = 'F1', string $rgb = '0.06 0.09 0.16') {
            return sprintf("BT %s rg /%s %.1f Tf %.2f %.2f Td (%s) Tj ET", $rgb, $font, $size, $x, $y, pdf_escape_text($str));
        };

        // ---- Page de garde ----
        $p = [];
        $p[] = $rect(0, $H - 90, $W, 90, '0.06 0.09 0.16');
        $p[] = $text('TICKET FLOW', 45, $H - 45, 24, 'F2', '1 1 1');
        $p[] = $text('Billetterie 100% Securisee - e-Billets officiels', 45, $H - 68, 10, 'F1', '0.22 0.74 0.97');
        $p[] = $text('Commande #' . $orderNumber, 45, $H - 125, 16, 'F2');
        $p[] = $text('Titulaire : ' . $clientName, 45, $H - 148, 11, 'F1', '0.39 0.45 0.55');
        $p[] = $text("Date d'emission : " . date('d/m/Y H:i'), 45, $H - 165, 10, 'F1', '0.39 0.45 0.55');
        $p[] = $text(count($tickets) . " billet(s) - presente chaque QR Code a l'entree. Valide une seule fois.", 45, $H - 190, 9.5, 'F1', '0.55 0.6 0.68');
        $ops_per_page[] = $p;

        // ---- Une page par billet ----
        foreach ($tickets as $t) {
            $p = [];
            $p[] = $rect(45, $H - 330, $W - 90, 45, '0.06 0.09 0.16');
            $p[] = $text('BILLET - ' . strtoupper((string)$t['type_ticket']), 60, $H - 313, 13, 'F2', '1 1 1');
            $p[] = $rect($W - 175, $H - 322, 80, 22, '0.06 0.73 0.51');
            $p[] = $text('VALIDE', $W - 158, $H - 316, 10, 'F2', '1 1 1');

            $p[] = $text((string)$t['event_name'], 45, $H - 370, 17, 'F2');
            $p[] = $text('Date : ' . date('d/m/Y', strtotime((string)$t['date_ev'])) . ' a ' . substr((string)$t['heure'], 0, 5), 60, $H - 402, 11);
            $p[] = $text('Lieu : ' . (string)$t['lieu'], 60, $H - 422, 11);
            $p[] = $text('Categorie : ' . (string)$t['type_ticket'], 60, $H - 442, 11);
            $y_prix = 462;
            if (!empty($t['place'])) {
                $p[] = $text('Place : ' . (string)$t['place'], 60, $H - 462, 11);
                $y_prix = 482;
            }
            $p[] = $text('Prix paye : ' . number_format((float)$t['prix'], 0, ',', ' ') . ' FCFA', 60, $H - $y_prix, 11, 'F2', '0.05 0.58 0.53');

            $p[] = $rect(45, $H - 505, $W - 90, 1.2, '0.89 0.91 0.95');
            $p[] = $text("Controle d'acces - presente ce code a l'agent", 45, $H - 530, 9, 'F1', '0.55 0.6 0.68');

            $qr = pdf_fetch_qr_jpeg((string)$t['qr_code']);
            if ($qr !== null) {
                $img_names[] = '/Im' . (count($images) + 1);
                $images[] = $qr;
                $qsize = 150.0;
                $qx = $W / 2 - $qsize / 2;
                $qy = $H - 545 - $qsize;
                $p[] = sprintf("q %.2f 0 0 %.2f %.2f %.2f cm %s Do Q", $qsize, $qsize, $qx, $qy, end($img_names));
            }

            $p[] = $rect($W / 2 - 90, $H - 735, 180, 28, '0.95 0.96 0.98');
            $p[] = $text((string)$t['code_unique'], $W / 2 - 62, $H - 726, 12, 'F2');
            $p[] = $text('Billetterie Ticket Flow - ticketflow.com', 45, 60, 8.5, 'F1', '0.55 0.6 0.68');
            $ops_per_page[] = $p;
        }
        // ---- Assemblage du document PDF (objets + table xref) ----
        $nb_pages = count($ops_per_page);
        $img_base = 5;                       // 1=Catalog, 2=Pages, 3=F1, 4=F2
        $page_base = $img_base + count($images); // puis 2 objets par page

        $objects = [];
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $kids = [];
        for ($i = 0; $i < $nb_pages; $i++) {
            $kids[] = ($page_base + $i * 2) . " 0 R";
        }
        $objects[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . $nb_pages . " >>";
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        // Objets images (QR Codes en JPEG / DCTDecode)
        $xref_list = [];
        foreach ($images as $idx => $img) {
            $n = $img_base + $idx;
            $xref_list[] = "/Im" . ($idx + 1) . " " . $n . " 0 R";
            $objects[$n] = "<< /Type /XObject /Subtype /Image /Width " . $img['w']
                . " /Height " . $img['h'] . " /ColorSpace /DeviceRGB /BitsPerComponent 8"
                . " /Filter /DCTDecode /Length " . strlen($img['data']) . " >>\nstream\n" . $img['data'] . "\nendstream";
        }

        // Objets pages + flux de contenu
        for ($i = 0; $i < $nb_pages; $i++) {
            $n_page = $page_base + $i * 2;
            $n_cont = $n_page + 1;
            $content = implode("\n", $ops_per_page[$i]);
            $resources = "<< /Font << /F1 3 0 R /F2 4 0 R >>";
            if (!empty($images)) {
                $resources .= " /XObject << " . implode(' ', $xref_list) . " >>";
            }
            $resources .= " >>";
            $objects[$n_page] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . sprintf("%.2f", $W) . " " . sprintf("%.2f", $H)
                . "] /Resources " . $resources . " /Contents " . $n_cont . " 0 R >>";
            $objects[$n_cont] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        }

        // Sérialisation avec offsets pour la table xref
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $max_obj = $page_base + $nb_pages * 2 - 1;
        for ($n = 1; $n <= $max_obj; $n++) {
            if (!isset($objects[$n])) { continue; }
            $offsets[$n] = strlen($pdf);
            $pdf .= $n . " 0 obj\n" . $objects[$n] . "\nendobj\n";
        }
        $xref_pos = strlen($pdf);
        $pdf .= "xref\n0 " . ($max_obj + 1) . "\n0000000000 65535 f \n";
        for ($n = 1; $n <= $max_obj; $n++) {
            $pdf .= isset($offsets[$n])
                ? sprintf("%010d 00000 n \n", $offsets[$n])
                : "0000000000 65535 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($max_obj + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref_pos . "\n%%EOF";

        return $pdf;
    }
}