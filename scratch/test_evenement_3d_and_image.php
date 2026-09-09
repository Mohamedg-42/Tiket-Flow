<?php
// Verification script for event poster display & 3D seat selection on client/evenement.php

$file = __DIR__ . '/../client/evenement.php';
$content = file_get_contents($file);

$errors = [];

// 1. Check poster contain & backdrop styles
if (!str_contains($content, '.event-hero-backdrop')) {
    $errors[] = "Missing .event-hero-backdrop CSS in client/evenement.php";
}
if (!str_contains($content, 'object-fit: contain')) {
    $errors[] = "Missing object-fit: contain on event-hero-img";
}
if (!str_contains($content, '<div class="event-hero-backdrop"')) {
    $errors[] = "Missing backdrop HTML element inside event-hero-media";
}

// 2. Check hero 3D CTA button
if (!str_contains($content, 'Choisir mes Places en 3D')) {
    $errors[] = "Missing 'Choisir mes Places en 3D' CTA button in hero";
}
if (!str_contains($content, 'openClient3DSeating(') || !str_contains($content, 'Choisir mes Places en 3D')) {
    $errors[] = "Missing openClient3DSeating in hero CTA button";
}

// 3. Check seat choice block in ticket cards
if (!str_contains($content, 'seat-choice-block')) {
    $errors[] = "Missing seat-choice-block in ticket cards";
}
if (!str_contains($content, 'seat_toggle_<?php echo $t_id; ?>')) {
    $errors[] = "Missing seat_toggle checkbox ID pattern";
}
if (!str_contains($content, 'toggleSeatMap(<?php echo $t_id; ?>')) {
    $errors[] = "Missing toggleSeatMap onchange handler";
}
if (!str_contains($content, 'btn-scene-interactive')) {
    $errors[] = "Missing btn-scene-interactive button";
}
if (!str_contains($content, 'openClient3DSeating(<?php echo $t_id; ?>')) {
    $errors[] = "Missing openClient3DSeating with ticket ID call";
}

// 4. Check form & hidden inputs
if (!str_contains($content, 'id="seat-hidden-inputs"')) {
    $errors[] = "Missing #seat-hidden-inputs inside eventCheckoutForm";
}

// 5. Check 3D modal integration
if (!str_contains($content, 'id="client3DSeatingModal"')) {
    $errors[] = "Missing #client3DSeatingModal markup";
}
if (!str_contains($content, 'id="client3DCanvas"')) {
    $errors[] = "Missing #client3DCanvas in modal";
}
if (!str_contains($content, 'applyClient3DSelection()')) {
    $errors[] = "Missing applyClient3DSelection call in 3D modal";
}

// 6. Check script tags
if (!str_contains($content, 'venue-3d-engine.js')) {
    $errors[] = "Missing venue-3d-engine.js script tag";
}
if (!str_contains($content, 'accueil-client.js')) {
    $errors[] = "Missing accueil-client.js script tag";
}

// 7. Check calculateEventTotal supports seat mode
if (!str_contains($content, 'data.seatMode') && !str_contains($content, 'dataset.seatMode')) {
    $errors[] = "calculateEventTotal does not check dataset.seatMode";
}

// Check js/accueil-client.js compatibility
$jsFile = __DIR__ . '/../js/accueil-client.js';
$jsContent = file_get_contents($jsFile);
if (!str_contains($jsContent, "document.getElementById('qty_input_' + ticketId) || document.getElementById('qty-input-' + ticketId)")) {
    $errors[] = "accueil-client.js syncTierSeats does not support qty-input- id";
}
if (!str_contains($jsContent, "document.querySelector('input[name=\"event_id\"]')?.value")) {
    $errors[] = "accueil-client.js openClient3DSeating does not support input[name=event_id] fallback";
}

if (empty($errors)) {
    echo "SUCCESS: All event poster and 3D seating verifications PASSED!\n";
    exit(0);
} else {
    echo "FAILED with " . count($errors) . " error(s):\n";
    foreach ($errors as $e) {
        echo " - " . $e . "\n";
    }
    exit(1);
}
