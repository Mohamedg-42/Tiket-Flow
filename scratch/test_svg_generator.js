const fs = require('fs');
const path = require('path');

function generateAmphitheatreSVG() {
    const cx = 460;
    const cy = 20; // Center of curvature
    const width = 920;
    const height = 660;

    // Defs for lights and shadows
    let svg = `
    <svg id="sbAmphiSvg" class="sb-amphitheatre-svg" viewBox="0 0 ${width} ${height}" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <filter id="seatGlow" x="-20%" y="-20%" width="140%" height="140%">
                <feDropShadow dx="0" dy="1" stdDeviation="1.5" flood-opacity="0.18" />
            </filter>
            <filter id="selectedGlow" x="-30%" y="-30%" width="160%" height="160%">
                <feDropShadow dx="0" dy="2" stdDeviation="3.5" flood-color="#2563EB" flood-opacity="0.6" />
            </filter>
            
            <!-- Spotlight Gradients -->
            <linearGradient id="beamGrad1" x1="0%" y1="0%" x2="50%" y2="100%">
                <stop offset="0%" stop-color="#FFFFFF" stop-opacity="0.45" />
                <stop offset="25%" stop-color="#BAE6FD" stop-opacity="0.25" />
                <stop offset="100%" stop-color="#0284C7" stop-opacity="0" />
            </linearGradient>
            <linearGradient id="beamGrad2" x1="20%" y1="0%" x2="40%" y2="100%">
                <stop offset="0%" stop-color="#FFFFFF" stop-opacity="0.5" />
                <stop offset="30%" stop-color="#BAE6FD" stop-opacity="0.22" />
                <stop offset="100%" stop-color="#0284C7" stop-opacity="0" />
            </linearGradient>
            <linearGradient id="beamGrad3" x1="80%" y1="0%" x2="60%" y2="100%">
                <stop offset="0%" stop-color="#FFFFFF" stop-opacity="0.5" />
                <stop offset="30%" stop-color="#BAE6FD" stop-opacity="0.22" />
                <stop offset="100%" stop-color="#0284C7" stop-opacity="0" />
            </linearGradient>
            <linearGradient id="beamGrad4" x1="100%" y1="0%" x2="50%" y2="100%">
                <stop offset="0%" stop-color="#FFFFFF" stop-opacity="0.45" />
                <stop offset="25%" stop-color="#BAE6FD" stop-opacity="0.25" />
                <stop offset="100%" stop-color="#0284C7" stop-opacity="0" />
            </linearGradient>
        </defs>

        <!-- Light Beams from Stage -->
        <g id="spotlightBeams" style="mix-blend-mode: normal; pointer-events: none;">
            <!-- Beam 1 (Left angled) -->
            <polygon points="380,85 180,480 290,490 395,85" fill="url(#beamGrad1)" opacity="0.6" />
            <!-- Beam 2 (Center Left) -->
            <polygon points="420,85 320,530 430,540 435,85" fill="url(#beamGrad2)" opacity="0.55" />
            <!-- Beam 3 (Center Right) -->
            <polygon points="485,85 490,540 600,530 500,85" fill="url(#beamGrad3)" opacity="0.55" />
            <!-- Beam 4 (Right angled) -->
            <polygon points="525,85 630,490 740,480 540,85" fill="url(#beamGrad4)" opacity="0.6" />
        </g>

        <!-- Stage Box (Trapezoid) -->
        <g id="stageGroup">
            <!-- Stage polygon -->
            <path d="M 320,38 L 600,38 L 575,90 L 345,90 Z" fill="#182230" rx="8" />
            <!-- Stage border highlight -->
            <path d="M 320,38 L 600,38" stroke="#334155" stroke-width="2" />
            <!-- Stage Text -->
            <text x="${cx}" y="67" font-family="'Plus Jakarta Sans', sans-serif" font-size="13" font-weight="900" fill="#FFFFFF" letter-spacing="4" text-anchor="middle">SCÈNE</text>
            <!-- 4 Light source lenses -->
            <circle cx="388" cy="88" r="4" fill="#FFFFFF" />
            <circle cx="388" cy="88" r="8" fill="#38BDF8" opacity="0.4" />
            <circle cx="428" cy="88" r="4" fill="#FFFFFF" />
            <circle cx="428" cy="88" r="8" fill="#38BDF8" opacity="0.4" />
            <circle cx="492" cy="88" r="4" fill="#FFFFFF" />
            <circle cx="492" cy="88" r="8" fill="#38BDF8" opacity="0.4" />
            <circle cx="532" cy="88" r="4" fill="#FFFFFF" />
            <circle cx="532" cy="88" r="8" fill="#38BDF8" opacity="0.4" />
        </g>

        <!-- Badges de zone flottants -->
        <!-- VIP Badges (Orange) -->
        <g id="vipBadges">
            <g transform="translate(305, 145)">
                <rect x="-24" y="-11" width="48" height="22" rx="11" fill="#FFF7ED" stroke="#FDBA74" stroke-width="1.4" />
                <text x="0" y="3.5" font-family="'Plus Jakarta Sans', sans-serif" font-size="9" font-weight="900" fill="#EA580C" text-anchor="middle">VIP</text>
            </g>
            <g transform="translate(615, 145)">
                <rect x="-24" y="-11" width="48" height="22" rx="11" fill="#FFF7ED" stroke="#FDBA74" stroke-width="1.4" />
                <text x="0" y="3.5" font-family="'Plus Jakarta Sans', sans-serif" font-size="9" font-weight="900" fill="#EA580C" text-anchor="middle">VIP</text>
            </g>
        </g>

        <!-- PREMIUM Badges (Blue) -->
        <g id="premBadges">
            <g transform="translate(200, 245)">
                <rect x="-36" y="-11" width="72" height="22" rx="11" fill="#EFF6FF" stroke="#93C5FD" stroke-width="1.4" />
                <text x="0" y="3.5" font-family="'Plus Jakarta Sans', sans-serif" font-size="8.5" font-weight="900" fill="#2563EB" text-anchor="middle">PREMIUM</text>
            </g>
            <g transform="translate(720, 245)">
                <rect x="-36" y="-11" width="72" height="22" rx="11" fill="#EFF6FF" stroke="#93C5FD" stroke-width="1.4" />
                <text x="0" y="3.5" font-family="'Plus Jakarta Sans', sans-serif" font-size="8.5" font-weight="900" fill="#2563EB" text-anchor="middle">PREMIUM</text>
            </g>
        </g>

        <!-- STANDARD Badges (Light Blue) -->
        <g id="stdBadges">
            <g transform="translate(200, 520)">
                <rect x="-38" y="-11" width="76" height="22" rx="11" fill="#F0FDF4" stroke="#93C5FD" stroke-width="1.4" />
                <text x="0" y="3.5" font-family="'Plus Jakarta Sans', sans-serif" font-size="8.5" font-weight="900" fill="#2563EB" text-anchor="middle">STANDARD</text>
            </g>
            <g transform="translate(720, 520)">
                <rect x="-38" y="-11" width="76" height="22" rx="11" fill="#F0FDF4" stroke="#93C5FD" stroke-width="1.4" />
                <text x="0" y="3.5" font-family="'Plus Jakarta Sans', sans-serif" font-size="8.5" font-weight="900" fill="#2563EB" text-anchor="middle">STANDARD</text>
            </g>
        </g>
    `;

    // Row definitions (A to P, 16 rows)
    const rowLetters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P'];
    const rStart = 152;
    const rStep = 24;

    // Occupied seat set for realistic preview matching mockup
    const occupied = new Set([
        'A5', 'A9', 'B8', 'B14', 'C4', 'C11', 'C16',
        'D5', 'D15', 'E8', 'E19', 'F9', 'F14', 'G8', 'G21',
        'H13', 'I8', 'I18', 'J10', 'J15', 'K9', 'K22',
        'L6', 'L18', 'M13', 'M23', 'N10', 'N20', 'O8', 'O17', 'P13', 'P23'
    ]);

    // PMR seats (wheelchair)
    const pmrSeats = new Set([
        'N1', 'P1', // Left outer wing
        'N26', 'P28', // Right outer wing
        'P15', 'P16', 'P17', 'P18' // Center bottom near régie
    ]);

    // Selected seats for preview (e.g. A12 and A13 in VIP)
    const selected = new Set(['A12', 'A13']);

    let seatsHTML = '<g id="seatsLayer">';
    let labelsHTML = '<g id="rowLabelsLayer">';

    // Store seat positions for tooltips or selection
    const seatCoords = {};

    rowLetters.forEach((letter, rIdx) => {
        const radius = rStart + rIdx * rStep;
        const isVIP = rIdx < 3; // Rows A, B, C
        const isPremium = rIdx >= 3 && rIdx < 11; // Rows D to K
        const isStandard = rIdx >= 11; // Rows L to P

        const defaultColor = isVIP ? '#F97316' : (isPremium ? '#2563EB' : '#C7D7EE');
        const catName = isVIP ? 'VIP' : (isPremium ? 'Premium' : 'Standard');
        const catPrice = isVIP ? 50000 : (isPremium ? 35000 : 20000);

        // Center sector seat count increases slightly from 12 (row A) to 20 (row P)
        const centerCount = Math.min(22, 12 + Math.floor(rIdx * 0.6));
        // Wing seat count increases from 4 (row A) to 8 (row P)
        const wingCount = Math.min(8, 4 + Math.floor(rIdx * 0.3));

        // Angular spacing between seats: constant arc distance ~13.8px
        const seatPitch = 13.8; 
        const dThetaDeg = (seatPitch / radius) * (180 / Math.PI);
        const aisleGapDeg = (22 / radius) * (180 / Math.PI);

        const centerSpanDeg = (centerCount - 1) * dThetaDeg;
        const wingSpanDeg = (wingCount - 1) * dThetaDeg;

        const centerStart = 90 + centerSpanDeg / 2;
        const centerEnd = 90 - centerSpanDeg / 2;

        const leftStart = centerStart + aisleGapDeg + wingSpanDeg;
        const leftEnd = centerStart + aisleGapDeg;

        const rightStart = centerEnd - aisleGapDeg;
        const rightEnd = centerEnd - aisleGapDeg - wingSpanDeg;

        // Row label on the left margin (clean vertical column at x ≈ 240)
        const labelAngle = leftStart * Math.PI / 180;
        const labelX = cx + radius * Math.cos(labelAngle) - 18;
        const labelY = cy + radius * Math.sin(labelAngle);

        labelsHTML += `
            <text x="${labelX.toFixed(1)}" y="${labelY.toFixed(1)}" font-family="'Inter', sans-serif" font-size="11" font-weight="700" fill="#94A3B8" text-anchor="middle" dominant-baseline="central">
                ${letter}
            </text>
        `;

        let seatGlobalNum = 1;

        function renderSeat(deg, num) {
            const rad = deg * Math.PI / 180;
            const sx = cx + radius * Math.cos(rad);
            const sy = cy + radius * Math.sin(rad);
            const rot = (rad * 180 / Math.PI) - 90;
            const seatId = `${letter}${num}`;

            seatCoords[seatId] = { x: sx, y: sy, rot: rot };

            const isOcc = occupied.has(seatId);
            const isSel = selected.has(seatId);
            const isPMR = pmrSeats.has(seatId);

            let fill = defaultColor;
            if (isOcc) fill = '#475569';
            if (isSel) fill = '#2563EB';

            const cssClass = (isOcc ? 'seat-occupied ' : '') + (isSel ? 'seat-selected ' : '');

            return `
                <g class="seat-node ${cssClass}" id="seat_node_${seatId}" data-seat-id="${seatId}" data-row="${letter}" data-num="${num}" data-cat="${catName}" data-price="${catPrice}" transform="translate(${sx.toFixed(1)}, ${sy.toFixed(1)}) rotate(${rot.toFixed(1)})" onclick="window.handleSeatClick('${seatId}')">
                    <rect x="-5.5" y="-5" width="11" height="9" rx="2.5" fill="${fill}" />
                    <rect x="-4.5" y="-1.5" width="9" height="5" rx="1.5" fill="${fill}" opacity="0.85" />
                    ${isPMR ? `
                        <circle cx="0" cy="-0.5" r="2.8" fill="#FFFFFF" />
                        <path d="M-0.8 -1.8 L0 -0.2 L0.8 -1" stroke="#2563EB" stroke-width="0.7" fill="none" />
                    ` : ''}
                </g>
            `;
        }

        // 1. Left Wing
        for (let i = 0; i < wingCount; i++) {
            const deg = leftStart - i * dThetaDeg;
            seatsHTML += renderSeat(deg, seatGlobalNum++);
        }

        // 2. Center Sector
        for (let i = 0; i < centerCount; i++) {
            const deg = centerStart - i * dThetaDeg;
            seatsHTML += renderSeat(deg, seatGlobalNum++);
        }

        // 3. Right Wing
        for (let i = 0; i < wingCount; i++) {
            const deg = rightStart - i * dThetaDeg;
            seatsHTML += renderSeat(deg, seatGlobalNum++);
        }
    });

    seatsHTML += '</g>';
    labelsHTML += '</g>';

    svg += labelsHTML;
    svg += seatsHTML;

    // Régie Box at bottom center
    svg += `
        <g id="regieGroup" transform="translate(${cx}, 555)">
            <rect x="-46" y="-16" width="92" height="32" rx="6" fill="#182230" />
            <text x="0" y="4" font-family="'Plus Jakarta Sans', sans-serif" font-size="10" font-weight="900" fill="#FFFFFF" letter-spacing="3" text-anchor="middle">RÉGIE</text>
        </g>
    `;

    // Tooltip over A12 & A13
    const a12 = seatCoords['A12'];
    const a13 = seatCoords['A13'];
    const tipX = (a12 && a13) ? ((a12.x + a13.x) / 2) : cx;
    const tipY = (a12 && a13) ? (Math.min(a12.y, a13.y) - 16) : 135;

    svg += `
        <g id="sbSelectedBubblesLayer">
            <g class="seat-selected-bubble" transform="translate(${tipX.toFixed(1)}, ${tipY.toFixed(1)})">
                <rect x="-26" y="-12" width="52" height="20" rx="6" fill="#0F172A" filter="url(#seatGlow)" />
                <polygon points="-4,8 4,8 0,12" fill="#0F172A" />
                <text x="-10" y="2" font-family="'Space Mono', monospace" font-size="9" font-weight="800" fill="#FFFFFF" text-anchor="middle">A12</text>
                <text x="10" y="2" font-family="'Space Mono', monospace" font-size="9" font-weight="800" fill="#FFFFFF" text-anchor="middle">A13</text>
            </g>
        </g>
    `;

    svg += `</svg>`;
    return svg;
}

const html = `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
body { margin: 0; background: #FAFCFF; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
.sb-amphitheatre-svg { width: 920px; height: 660px; }
.seat-node { cursor: pointer; transition: transform 0.15s ease; }
.seat-node:hover:not(.seat-occupied) { transform: scale(1.4); }
.seat-occupied { opacity: 0.6; cursor: not-allowed; }
</style>
</head>
<body>
${generateAmphitheatreSVG()}
</body>
</html>`;

fs.writeFileSync(path.join(__dirname, 'test_amphi.html'), html);
console.log('Fichier test_amphi.html généré avec succès !');
