<?php
// ==============================================================================
// GESTION DU BARÈME DE COMMISSION SELON L'AMPLEUR DE L'ÉVÉNEMENT
// (includes/commission.php)
// Plateforme Tikéli — Barème dégressif par paliers de capacité & recette
// ==============================================================================

if (!function_exists('get_event_scale_tier')) {
    /**
     * Détermine le barème de commission applicable selon l'ampleur de l'événement.
     * Paliers définis :
     *  - Intimiste (< 300 places) : 7.0%
     *  - Standard (300 à 1 499 places) : 5.0%
     *  - Grand Événement (1 500 à 4 999 places) : 4.0%
     *  - Festival & Stade (≥ 5 000 places) : 3.0%
     *
     * @param int|float $capacity Nombre total de places de l'événement
     * @param float $gross_revenue Recette brute totale estimée (FCFA)
     * @return array Informations complètes sur le palier
     */
    function get_event_scale_tier($capacity, $gross_revenue = 0) {
        $capacity = (int)$capacity;
        $gross_revenue = (float)$gross_revenue;

        if ($capacity >= 5000 || $gross_revenue >= 50000000) {
            return [
                'tier'          => 'mega',
                'name'          => 'Festival & Stade',
                'rate'          => 3.0,
                'min_capacity'  => 5000,
                'max_capacity'  => null,
                'range_label'   => '≥ 5 000 places',
                'badge_short'   => 'Festival (≥ 5K)',
                'badge_color'   => '#FF4A0D',
                'badge_bg'      => '#FFF2ED',
                'payout_pct'    => 97.0,
                'description'   => 'Grands festivals, stades et méga-événements culturels'
            ];
        } elseif ($capacity >= 1500 || $gross_revenue >= 15000000) {
            return [
                'tier'          => 'grand',
                'name'          => 'Grand Événement',
                'rate'          => 4.0,
                'min_capacity'  => 1500,
                'max_capacity'  => 4999,
                'range_label'   => '1 500 – 4 999 places',
                'badge_short'   => 'Grand Evt (1.5K-5K)',
                'badge_color'   => '#000000',
                'badge_bg'      => '#F5F5F5',
                'payout_pct'    => 96.0,
                'description'   => 'Salles d\'envergure, Palais de la Culture, grandes salles'
            ];
        } elseif ($capacity >= 300 || $gross_revenue >= 3000000) {
            return [
                'tier'          => 'standard',
                'name'          => 'Événement Standard',
                'rate'          => 5.0,
                'min_capacity'  => 300,
                'max_capacity'  => 1499,
                'range_label'   => '300 – 1 499 places',
                'badge_short'   => 'Standard (300-1.5K)',
                'badge_color'   => '#FF4A0D',
                'badge_bg'      => '#FFF2ED',
                'payout_pct'    => 95.0,
                'description'   => 'Salles moyennes, concerts club, conférences, pièces de théâtre'
            ];
        } else {
            return [
                'tier'          => 'intimiste',
                'name'          => 'Événement Intimiste',
                'rate'          => 7.0,
                'min_capacity'  => 1,
                'max_capacity'  => 299,
                'range_label'   => '< 300 places',
                'badge_short'   => 'Intimiste (< 300)',
                'badge_color'   => '#737373',
                'badge_bg'      => '#F5F5F5',
                'payout_pct'    => 93.0,
                'description'   => 'Soirées intimistes, clubs, showcases privés, ateliers'
            ];
        }
    }
}

if (!function_exists('render_scale_badge_html')) {
    /**
     * Génère un badge HTML élégant résumant l'ampleur et le taux
     */
    function render_scale_badge_html($tier_data) {
        $name = htmlspecialchars($tier_data['name']);
        $rate = number_format($tier_data['rate'], 1);
        $bg   = $tier_data['badge_bg'];
        $fg   = $tier_data['badge_color'];

        return '<span style="display:inline-flex; align-items:center; gap:5px; background:' . $bg . '; color:' . $fg . '; border:1px solid ' . $fg . '25; border-radius:6px; padding:2px 8px; font-size:0.75rem; font-weight:700;">' .
               '<i class="fa-solid fa-chart-pie" style="font-size:0.7rem;"></i> ' .
               $name . ' • ' . $rate . '%' .
               '</span>';
    }
}
