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
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'Eventia/1.0']]);
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
     * Format carte e-Ticket horizontale exacte (Partie principale blanche + Ligne pointillée + Talon QR Code).
     *
     * @param array  $tickets      Billets : code_unique, qr_code, event_name, type_ticket, place/place_numero, prix, date_ev/date_evenement, heure, lieu, date_achat
     * @param string $orderNumber  Numéro de commande
     * @param string $clientName   Nom du client
     * @return string              Binaire PDF (chaîne vide si aucun billet)
     */
    function generateTicketsPdf(array $tickets, string $orderNumber, string $clientName): string
    {
        if (empty($tickets)) {
            return '';
        }

        $W = 595.28;  // A4 Largeur en points
        $H = 841.89;  // A4 Hauteur en points

        $ops_per_page = [];
        $images = [];
        $img_names = [];

        $rect = function (float $x, float $y, float $w, float $h, string $rgb) {
            return sprintf("%s rg %.2f %.2f %.2f %.2f re f", $rgb, $x, $y, $w, $h);
        };
        $text = function (string $str, float $x, float $y, float $size, string $font = 'F1', string $rgb = '0 0 0') {
            return sprintf("BT %s rg /%s %.1f Tf %.2f %.2f Td (%s) Tj ET", $rgb, $font, $size, $x, $y, pdf_escape_text($str));
        };

        $roundedRect = function (float $x, float $y, float $w, float $h, float $r, string $strokeRgb = '0 0 0', string $fillRgb = '1 1 1', float $lineWidth = 1.5) {
            $k = 0.5522847498 * $r;
            return sprintf(
                "%s rg %s RG %.2f w %.2f %.2f m " .
                "%.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c " .
                "%.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c " .
                "%.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c " .
                "%.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c " .
                "h B",
                $fillRgb, $strokeRgb, $lineWidth,
                $x + $r, $y + $h,
                $x + $w - $r, $y + $h,
                $x + $w - $r + $k, $y + $h, $x + $w, $y + $h - $r + $k, $x + $w, $y + $h - $r,
                $x + $w, $y + $r,
                $x + $w, $y + $r - $k, $x + $w - $r + $k, $y, $x + $w - $r, $y,
                $x + $r, $y,
                $x + $r - $k, $y, $x, $y + $r - $k, $x, $y + $r,
                $x, $y + $h - $r,
                $x, $y + $h - $r + $k, $x + $r - $k, $y + $h, $x + $r, $y + $h
            );
        };

        // Si la commande comporte plus d'un billet, page de garde récapitulative
        if (count($tickets) > 1) {
            $p = [];
            $p[] = $rect(0, $H - 75, $W, 75, '0.05 0.58 0.53'); // Teal Eventia
            $p[] = $text('EVENTIA', 45, $H - 45, 24, 'F2', '1 1 1');
            $p[] = $text('Billetterie 100% Securisee - e-Billets officiels', 45, $H - 65, 10, 'F1', '1 1 1');
            $p[] = $text('Commande #' . $orderNumber, 45, $H - 115, 16, 'F2', '0 0 0');
            $p[] = $text('Titulaire : ' . $clientName, 45, $H - 138, 11, 'F1', '0.39 0.45 0.55');
            $p[] = $text("Date d'emission : " . date('d/m/Y H:i'), 45, $H - 155, 10, 'F1', '0.39 0.45 0.55');
            $p[] = $text(count($tickets) . " billet(s) dans ce document. Chaque page ci-apres contient un billet officiel avec QR Code.", 45, $H - 185, 9.5, 'F1', '0.55 0.6 0.68');
            $ops_per_page[] = $p;
        }

        // ---- Une page par billet au format exact de la capture ----
        foreach ($tickets as $idx => $t) {
            $p = [];
            $eventName  = (string)($t['event_name'] ?? $t['nom'] ?? 'Evenement');
            $dateRaw    = $t['date_ev'] ?? $t['date_evenement'] ?? '';
            $dateStr    = !empty($dateRaw) ? date('d/m/Y', strtotime((string)$dateRaw)) : '';
            $heureStr   = !empty($t['heure']) ? substr((string)$t['heure'], 0, 5) : '';
            $lieuStr    = (string)($t['lieu'] ?? $t['event_lieu'] ?? '');
            $typeStr    = (string)($t['type_ticket'] ?? 'Standard');
            $placeStr   = (string)(!empty($t['place_numero']) ? $t['place_numero'] : (!empty($t['place']) ? $t['place'] : ''));
            $prixNum    = (float)($t['prix'] ?? 0);
            $codeUnique = (string)($t['code_unique'] ?? '');
            $tClient    = (string)(!empty($t['client_nom']) ? $t['client_nom'] : $clientName);
            $dateAchat  = !empty($t['date_achat']) ? date('d/m/Y H:i', strtotime((string)$t['date_achat'])) : date('d/m/Y H:i');

            // En-tête discret de page
            $p[] = $text(date('d/m/Y H:i'), 38, $H - 40, 8.5, 'F1', '0.2 0.2 0.2');
            $p[] = $text('e-Ticket Officiel - Eventia', $W / 2 - 50, $H - 40, 8.5, 'F1', '0.2 0.2 0.2');

            // 1. Dimensions de la Carte Billet
            $cardX = 38.0;
            $cardY = $H - 365.0;
            $cardW = 520.0;
            $cardH = 300.0;
            $splitX = $cardX + 345.0; // 383.0

            // Carte avec BORDS ARRONDIS et contour noir
            $p[] = $roundedRect($cardX, $cardY, $cardW, $cardH, 18.0, '0 0 0', '1 1 1', 1.8);

            // Logo TIKÉLI (Orange #ff5500)
            $p[] = $text('TIKÉLI', $cardX + 22, $cardY + $cardH - 38, 15, 'F2', '1.0 0.33 0.0'); // Tikéli Orange
            
            // Badge VENDU / VALIDE avec bords arrondis parfaitement centré
            $badgeW = 66.0;
            $badgeH = 20.0;
            $badgeX = $splitX - $badgeW - 20;
            $badgeY = $cardY + $cardH - 42.0;
            $p[] = $roundedRect($badgeX, $badgeY, $badgeW, $badgeH, 10.0, '0.4 0.8 0.6', '0.93 0.99 0.96', 1.0);
            $p[] = $text('VENDU', $badgeX + 17, $badgeY + 6.0, 8.5, 'F2', '0.02 0.37 0.27');

            // Grand Titre de l'événement (LA PONA)
            $p[] = $text(strtoupper($eventName), $cardX + 22, $cardY + $cardH - 78, 18, 'F2', '0 0 0');

            // Grille 2 Colonnes
            $col1X = $cardX + 22;
            $col2X = $cardX + 185;

            // Date & Heure
            $p[] = $text('DATE & HEURE', $col1X, $cardY + $cardH - 110, 7.5, 'F2', '0.35 0.4 0.5');
            $p[] = $text($dateStr . ($heureStr ? ' a ' . $heureStr : ''), $col1X, $cardY + $cardH - 126, 11, 'F2', '0 0 0');

            // Salle & Lieu
            $p[] = $text('SALLE & LIEU', $col2X, $cardY + $cardH - 110, 7.5, 'F2', '0.35 0.4 0.5');
            $p[] = $text($lieuStr, $col2X, $cardY + $cardH - 126, 11, 'F2', '0 0 0');

            // Catégorie
            $p[] = $text('CATEGORIE DE BILLET', $col1X, $cardY + $cardH - 155, 7.5, 'F2', '0.35 0.4 0.5');
            $p[] = $text(strtoupper($typeStr), $col1X, $cardY + $cardH - 171, 11, 'F2', '0 0 0');

            // Place (si présente)
            if (!empty($placeStr)) {
                $p[] = $text('PLACE', $col2X, $cardY + $cardH - 155, 7.5, 'F2', '0.35 0.4 0.5');
                $p[] = $text($placeStr, $col2X, $cardY + $cardH - 171, 11, 'F2', '0 0 0');
            }

            // Prix Payé
            $p[] = $text('PRIX PAYE', $col1X, $cardY + $cardH - 200, 7.5, 'F2', '0.35 0.4 0.5');
            $p[] = $text(number_format($prixNum, 0, ',', ' ') . ' FCFA', $col1X, $cardY + $cardH - 216, 12, 'F2', '0 0 0');

            // Barre Titulaire & Date d'achat (Bas de la partie gauche)
            $p[] = $text('Titulaire : ' . $tClient, $col1X, $cardY + 28, 9, 'F2', '0 0 0');
            $p[] = $text("Date d'achat : " . $dateAchat, $col2X, $cardY + 28, 9, 'F1', '0.2 0.2 0.2');

            // 3. LIGNE VERTICALE POINTILLÉE / TIRETS (PERFORATION)
            $p[] = sprintf("q [3 3] 0 d 1.5 w 0.1 0.1 0.1 RG %.2f %.2f m %.2f %.2f l S Q", $splitX, $cardY, $splitX, $cardY + $cardH);

            // 4. PARTIE DROITE DU BILLET (TALON AVEC QR CODE)
            $stubW = $cardW - 345.0; // 175.0
            $stubCenterX = $splitX + ($stubW / 2);

            // Image QR Code
            $qr = pdf_fetch_qr_jpeg((string)($t['qr_code'] ?? ''));
            if ($qr !== null) {
                $img_names[] = '/Im' . (count($images) + 1);
                $images[] = $qr;
                $qrSize = 130.0;
                $qrX = $stubCenterX - ($qrSize / 2);
                $qrY = $cardY + $cardH - 148.0;
                $p[] = sprintf("q %.2f 0 0 %.2f %.2f %.2f cm %s Do Q", $qrSize, $qrSize, $qrX, $qrY, end($img_names));
            }

            // Code Unique en gras centré
            $textCodeX = $stubCenterX - (strlen($codeUnique) * 3.8);
            $p[] = $text($codeUnique, max($splitX + 10, $textCodeX), $cardY + 125, 11.5, 'F2', '0 0 0');

            // Catégorie en gras centré
            $textTierX = $stubCenterX - (strlen($typeStr) * 4.0);
            $p[] = $text(strtoupper($typeStr), max($splitX + 10, $textTierX), $cardY + 102, 12, 'F2', '0 0 0');

            // Prix
            $prixF = number_format($prixNum, 0, ',', ' ') . ' F';
            $textPrixX = $stubCenterX - (strlen($prixF) * 3.4);
            $p[] = $text($prixF, max($splitX + 10, $textPrixX), $cardY + 84, 10, 'F1', '0.2 0.2 0.2');

            // Consigne QR Code
            $p[] = $text("Presentez ce QR Code a l'agent de", $splitX + 16, $cardY + 42, 6.8, 'F1', '0.39 0.45 0.55');
            $p[] = $text("controle", $stubCenterX - 14, $cardY + 30, 6.8, 'F1', '0.39 0.45 0.55');

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