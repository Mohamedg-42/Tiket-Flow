/**
 * ==============================================================================
 * TIKÉLI / TIKEWA — SEATING & BOOKING INTERACTIVE ENGINE (js/seating-booking.js)
 * Plan interactif de la salle (Amphithéâtre 3 Sectors, Scène avec projecteurs)
 * & Panier dynamique en temps réel conforme à la maquette officielle Tikéli
 * ==============================================================================
 */

(function () {
    'use strict';

    // État global de réservation
    const state = {
        eventId: window.BOOKING_EVENT_ID || null,
        tickets: window.BOOKING_TICKETS || [], // [{id, nom, prix, frais_place, quantite, quantite_vendue}, ...]
        selectedSeats: new Map(), // key: 'A12' -> { id, row, num, category, price, ticketTypeId, fee }
        occupiedSeats: new Set([
            'A5', 'A9', 'B8', 'B14', 'C4', 'C11', 'C16',
            'D5', 'D15', 'E8', 'E19', 'F9', 'F14', 'G8', 'G21',
            'H13', 'I8', 'I18', 'J10', 'J15', 'K9', 'K22',
            'L6', 'L18', 'M13', 'M23', 'N10', 'N20', 'O8', 'O17', 'P13', 'P23'
        ]),
        pmrSeats: new Set([
            'N1', 'P1', // Tribune latérale gauche
            'N26', 'P28', // Tribune latérale droite
            'P15', 'P16', 'P17', 'P18' // Parterre central devant la régie
        ]),
        seatCoords: {}, // id -> { x, y, rot }
        zoomLevel: 1.0,
        serviceFeePerTicket: 1000 // Frais de service fixes par billet (FCFA)
    };

    // Mapping des catégories de tarifs vers les types de billets réels
    function getCategoryInfo(rowLetter) {
        // Rangées A-C : VIP (Orange)
        if (['A', 'B', 'C'].includes(rowLetter)) {
            const vipTicket = state.tickets.find(t => t.nom.toLowerCase().includes('vip')) 
                || (state.tickets.length > 0 ? [...state.tickets].sort((a,b) => b.prix - a.prix)[0] : null);
            return {
                name: 'VIP',
                color: '#F97316',
                ticketId: vipTicket ? vipTicket.id : 2,
                price: vipTicket ? parseFloat(vipTicket.prix) : 50000,
                fee: 1000
            };
        }
        // Rangées D-K : Premium (Bleu Roi)
        if (['D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'].includes(rowLetter)) {
            const premTicket = state.tickets.find(t => t.nom.toLowerCase().includes('prem') || t.nom.toLowerCase().includes('vvip') || t.nom.toLowerCase().includes('or'))
                || (state.tickets.length > 1 ? [...state.tickets].sort((a,b) => b.prix - a.prix)[1] : state.tickets[0]);
            return {
                name: 'Premium',
                color: '#2563EB',
                ticketId: premTicket ? premTicket.id : 30,
                price: premTicket ? parseFloat(premTicket.prix) : 35000,
                fee: 1000
            };
        }
        // Rangées L-P : Standard (Bleu Ciel Pastel)
        const stdTicket = state.tickets.find(t => t.nom.toLowerCase().includes('stand') || t.nom.toLowerCase().includes('pass') || t.nom.toLowerCase().includes('class'))
            || (state.tickets.length > 0 ? [...state.tickets].sort((a,b) => a.prix - b.prix)[0] : null);
        return {
            name: 'Standard',
            color: '#BACBE5',
            ticketId: stdTicket ? stdTicket.id : 1,
            price: stdTicket ? parseFloat(stdTicket.prix) : 20000,
            fee: 1000
        };
    }

    // Génération géométrique de l'amphithéâtre vectoriel fidèle à la maquette
    function renderAmphitheatreSVG() {
        const svgContainer = document.getElementById('amphitheatrePlanContainer');
        if (!svgContainer) return;

        const cx = 460;
        const cy = 20; // Centre focal de courbure vers la scène

        let svgHTML = `
        <svg id="sbAmphiSvg" class="sb-amphitheatre-svg" viewBox="125 20 670 565" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <filter id="seatGlow" x="-20%" y="-20%" width="140%" height="140%">
                    <feDropShadow dx="0" dy="1" stdDeviation="1.5" flood-opacity="0.18" />
                </filter>
                <filter id="selectedGlow" x="-30%" y="-30%" width="160%" height="160%">
                    <feDropShadow dx="0" dy="2" stdDeviation="3.5" flood-color="#2563EB" flood-opacity="0.6" />
                </filter>
                
                <!-- Dégradés des faisceaux lumineux de la Scène -->
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

            <!-- Faisceaux lumineux de la Scène -->
            <g id="spotlightBeams" style="pointer-events: none;">
                <polygon points="380,85 180,480 290,490 395,85" fill="url(#beamGrad1)" opacity="0.6" />
                <polygon points="420,85 320,530 430,540 435,85" fill="url(#beamGrad2)" opacity="0.55" />
                <polygon points="485,85 490,540 600,530 500,85" fill="url(#beamGrad3)" opacity="0.55" />
                <polygon points="525,85 630,490 740,480 540,85" fill="url(#beamGrad4)" opacity="0.6" />
            </g>

            <!-- Boîte de la Scène (Trapèze sombre) -->
            <g id="stageGroup">
                <path d="M 320,38 L 600,38 L 575,90 L 345,90 Z" fill="#182230" rx="8" />
                <path d="M 320,38 L 600,38" stroke="#334155" stroke-width="2" />
                <text x="${cx}" y="67" font-family="'Plus Jakarta Sans', sans-serif" font-size="13" font-weight="900" fill="#FFFFFF" letter-spacing="4" text-anchor="middle">SCÈNE</text>
                
                <!-- Lentilles des 4 projecteurs -->
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
                <g transform="translate(295, 125)">
                    <rect x="-24" y="-11" width="48" height="22" rx="11" fill="#FFF7ED" stroke="#FDBA74" stroke-width="1.4" />
                    <text x="0" y="3.5" font-family="'Plus Jakarta Sans', sans-serif" font-size="9" font-weight="900" fill="#EA580C" text-anchor="middle">VIP</text>
                </g>
                <g transform="translate(625, 125)">
                    <rect x="-24" y="-11" width="48" height="22" rx="11" fill="#FFF7ED" stroke="#FDBA74" stroke-width="1.4" />
                    <text x="0" y="3.5" font-family="'Plus Jakarta Sans', sans-serif" font-size="9" font-weight="900" fill="#EA580C" text-anchor="middle">VIP</text>
                </g>
            </g>

            <!-- PREMIUM Badges (Bleu Roi) -->
            <g id="premBadges">
                <g transform="translate(205, 250)">
                    <rect x="-36" y="-11" width="72" height="22" rx="11" fill="#EFF6FF" stroke="#93C5FD" stroke-width="1.4" />
                    <text x="0" y="3.5" font-family="'Plus Jakarta Sans', sans-serif" font-size="8.5" font-weight="900" fill="#2563EB" text-anchor="middle">PREMIUM</text>
                </g>
                <g transform="translate(715, 250)">
                    <rect x="-36" y="-11" width="72" height="22" rx="11" fill="#EFF6FF" stroke="#93C5FD" stroke-width="1.4" />
                    <text x="0" y="3.5" font-family="'Plus Jakarta Sans', sans-serif" font-size="8.5" font-weight="900" fill="#2563EB" text-anchor="middle">PREMIUM</text>
                </g>
            </g>

            <!-- STANDARD Badges (Bleu Ciel) -->
            <g id="stdBadges">
                <g transform="translate(205, 510)">
                    <rect x="-38" y="-11" width="76" height="22" rx="11" fill="#F0FDF4" stroke="#93C5FD" stroke-width="1.4" />
                    <text x="0" y="3.5" font-family="'Plus Jakarta Sans', sans-serif" font-size="8.5" font-weight="900" fill="#2563EB" text-anchor="middle">STANDARD</text>
                </g>
                <g transform="translate(715, 510)">
                    <rect x="-38" y="-11" width="76" height="22" rx="11" fill="#F0FDF4" stroke="#93C5FD" stroke-width="1.4" />
                    <text x="0" y="3.5" font-family="'Plus Jakarta Sans', sans-serif" font-size="8.5" font-weight="900" fill="#2563EB" text-anchor="middle">STANDARD</text>
                </g>
            </g>
        `;

        // Rangées A à P (16 rangées)
        const rowLetters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P'];
        const rStart = 152;
        const rStep = 24;

        state.seatCoords = {};

        let seatsHTML = '<g id="seatsLayer">';
        let labelsHTML = '<g id="rowLabelsLayer">';

        rowLetters.forEach((letter, rIdx) => {
            const radius = rStart + rIdx * rStep;
            const cat = getCategoryInfo(letter);

            // Nombre de sièges par secteur
            const centerCount = Math.min(22, 12 + Math.floor(rIdx * 0.6));
            const wingCount = Math.min(8, 4 + Math.floor(rIdx * 0.3));

            // Pas angulaire constant pour espacement uniforme ~13.8px
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

            // Lettre de rangée sur la marge gauche (colonne verticale droite et propre)
            const labelX = 145;
            const labelY = cy + radius * Math.sin(leftStart * Math.PI / 180);

            labelsHTML += `
                <text x="${labelX}" y="${labelY.toFixed(1)}" font-family="'Inter', sans-serif" font-size="11" font-weight="700" fill="#94A3B8" text-anchor="middle" dominant-baseline="central">
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

                state.seatCoords[seatId] = { x: sx, y: sy, rot: rot, row: letter, num: num };

                const isOcc = state.occupiedSeats.has(seatId);
                const isSel = state.selectedSeats.has(seatId);
                const isPMR = state.pmrSeats.has(seatId);

                let fill = cat.color;
                if (isOcc) fill = '#475569';
                if (isSel) fill = '#2563EB';

                const cursorClass = isOcc ? 'seat-occupied ' : '';
                const selectedClass = isSel ? 'seat-selected ' : '';

                return `
                    <g class="seat-node ${cursorClass} ${selectedClass}" id="seat_node_${seatId}"
                       data-seat-id="${seatId}" data-row="${letter}" data-num="${num}"
                       data-cat="${cat.name}" data-price="${cat.price}" data-ticket-id="${cat.ticketId}" data-fee="${cat.fee}"
                       transform="translate(${sx.toFixed(1)}, ${sy.toFixed(1)}) rotate(${rot.toFixed(1)})"
                       onclick="window.handleSeatClick('${seatId}')">
                        <!-- Dossier du siège -->
                        <rect x="-5.5" y="-5" width="11" height="9" rx="2.5" fill="${fill}" />
                        <!-- Assise douce -->
                        <rect x="-4.5" y="-1.5" width="9" height="5" rx="1.5" fill="${fill}" opacity="0.85" />
                        ${isPMR ? `
                            <!-- Icône PMR Fauteuil Roulant -->
                            <circle cx="0" cy="-0.5" r="2.8" fill="#FFFFFF" />
                            <path d="M-0.8 -1.8 L0 -0.2 L0.8 -1" stroke="#2563EB" stroke-width="0.7" fill="none" />
                        ` : ''}
                    </g>
                `;
            }

            // 1. Tribune gauche
            for (let i = 0; i < wingCount; i++) {
                const deg = leftStart - i * dThetaDeg;
                seatsHTML += renderSeat(deg, seatGlobalNum++);
            }

            // 2. Parterre central
            for (let i = 0; i < centerCount; i++) {
                const deg = centerStart - i * dThetaDeg;
                seatsHTML += renderSeat(deg, seatGlobalNum++);
            }

            // 3. Tribune droite
            for (let i = 0; i < wingCount; i++) {
                const deg = rightStart - i * dThetaDeg;
                seatsHTML += renderSeat(deg, seatGlobalNum++);
            }
        });

        seatsHTML += '</g>';
        labelsHTML += '</g>';

        svgHTML += labelsHTML;
        svgHTML += seatsHTML;

        // Cabine RÉGIE au centre en bas
        svgHTML += `
            <g id="regieGroup" transform="translate(${cx}, 555)">
                <rect x="-46" y="-16" width="92" height="32" rx="6" fill="#182230" />
                <text x="0" y="4" font-family="'Plus Jakarta Sans', sans-serif" font-size="10" font-weight="900" fill="#FFFFFF" letter-spacing="3" text-anchor="middle">RÉGIE</text>
            </g>
        `;

        // Calque pour les infobulles flottantes des sièges sélectionnés
        svgHTML += `<g id="sbSelectedBubblesLayer"></g>`;

        svgHTML += `</svg>`;
        svgContainer.innerHTML = svgHTML;
        updateSelectedBubbles();
    }

    // Mise à jour de l'infobulle unifiée au-dessus des sièges sélectionnés
    function updateSelectedBubbles() {
        const layer = document.getElementById('sbSelectedBubblesLayer');
        if (!layer) return;

        if (state.selectedSeats.size === 0) {
            layer.innerHTML = '';
            return;
        }

        const selIds = Array.from(state.selectedSeats.keys());
        
        // Calcul du barycentre des sièges sélectionnés
        let sumX = 0;
        let minY = Infinity;
        let validCount = 0;

        selIds.forEach(id => {
            const coord = state.seatCoords[id];
            if (coord) {
                sumX += coord.x;
                if (coord.y < minY) minY = coord.y;
                validCount++;
            }
        });

        if (validCount === 0) return;

        const tipX = sumX / validCount;
        const tipY = minY - 16;

        // Afficher jusqu'à 3 identifiants dans l'infobulle
        const displayLabels = selIds.slice(0, 3);
        const hasMore = selIds.length > 3;
        const bubbleWidth = Math.max(54, displayLabels.length * 28 + (hasMore ? 20 : 0));

        let textSpans = '';
        const startOffset = -(bubbleWidth / 2) + 16;
        displayLabels.forEach((id, idx) => {
            textSpans += `<text x="${startOffset + idx * 26}" y="2" font-family="'Space Mono', monospace" font-size="9" font-weight="800" fill="#FFFFFF" text-anchor="middle">${id}</text>`;
        });
        if (hasMore) {
            textSpans += `<text x="${startOffset + displayLabels.length * 26}" y="2" font-family="'Inter', sans-serif" font-size="8" font-weight="700" fill="#93C5FD" text-anchor="middle">+${selIds.length - 3}</text>`;
        }

        layer.innerHTML = `
            <g class="seat-selected-bubble" transform="translate(${tipX.toFixed(1)}, ${tipY.toFixed(1)})">
                <rect x="${-(bubbleWidth / 2)}" y="-12" width="${bubbleWidth}" height="20" rx="6" fill="#0F172A" filter="url(#seatGlow)" />
                <polygon points="-4,8 4,8 0,12" fill="#0F172A" />
                ${textSpans}
            </g>
        `;
    }

    // Clic interactif sur un siège de la salle
    window.handleSeatClick = function (seatId) {
        if (state.occupiedSeats.has(seatId)) return;

        const node = document.getElementById(`seat_node_${seatId}`);
        if (!node) return;

        if (state.selectedSeats.has(seatId)) {
            // Désélectionner
            state.selectedSeats.delete(seatId);
            const cat = getCategoryInfo(node.getAttribute('data-row'));
            node.classList.remove('seat-selected');
            node.querySelector('rect').setAttribute('fill', cat.color);
        } else {
            // Limite de sélection max (8 places)
            if (state.selectedSeats.size >= 8) {
                alert("Vous pouvez sélectionner jusqu'à 8 places par commande.");
                return;
            }

            const row = node.getAttribute('data-row');
            const num = node.getAttribute('data-num');
            const cat = node.getAttribute('data-cat');
            const price = parseFloat(node.getAttribute('data-price')) || 0;
            const ticketId = parseInt(node.getAttribute('data-ticket-id'), 10);
            const fee = parseFloat(node.getAttribute('data-fee')) || 1000;

            state.selectedSeats.set(seatId, {
                id: seatId,
                row: row,
                num: num,
                category: cat,
                price: price,
                ticketId: ticketId,
                fee: fee
            });

            node.classList.add('seat-selected');
            node.querySelector('rect').setAttribute('fill', '#2563EB');
        }

        updateSelectedBubbles();
        renderCart();
    };

    // Retirer un siège depuis le panier
    window.removeSeatFromCart = function (seatId) {
        window.handleSeatClick(seatId);
    };

    // Mise à jour en temps réel du panier ("Vos places")
    function renderCart() {
        const countBadge = document.getElementById('sbCartCountBadge');
        const seatsListEl = document.getElementById('sbCartSeatsList');
        const subtotalEl = document.getElementById('sbCartSubtotal');
        const feesEl = document.getElementById('sbCartFees');
        const totalEl = document.getElementById('sbCartTotal');
        const payBtn = document.getElementById('sbBtnContinuePay');
        const subtotalLabelEl = document.getElementById('sbCartSubtotalLabel');

        const count = state.selectedSeats.size;
        if (countBadge) countBadge.textContent = count;

        if (count === 0) {
            if (seatsListEl) {
                seatsListEl.innerHTML = `
                    <div class="cart-empty-state">
                        <i class="fa-solid fa-hand-pointer" style="font-size: 1.5rem; color: #94A3B8; margin-bottom: 6px; display: block;"></i>
                        Sélectionnez un ou plusieurs sièges sur le plan pour continuer.
                    </div>
                `;
            }
            if (subtotalEl) subtotalEl.textContent = '0 FCFA';
            if (feesEl) feesEl.textContent = '0 FCFA';
            if (totalEl) totalEl.textContent = '0 FCFA';
            if (subtotalLabelEl) subtotalLabelEl.textContent = 'Sous-total (0 billet)';
            if (payBtn) payBtn.disabled = true;
            return;
        }

        let itemsHTML = '';
        let subtotal = 0;
        let totalFees = 0;

        state.selectedSeats.forEach((seat, seatId) => {
            subtotal += seat.price;
            totalFees += seat.fee;

            const dotClass = seat.category.toLowerCase().includes('vip') ? 'dot-vip' :
                (seat.category.toLowerCase().includes('prem') ? 'dot-premium' : 'dot-standard');

            itemsHTML += `
                <div class="cart-seat-item" id="cart_item_${seatId}">
                    <div class="cart-seat-item-top">
                        <div class="cart-seat-name-wrap">
                            <span class="event-tarif-dot ${dotClass}"></span>
                            <span>${seatId} · Rangée ${seat.row} · Siège ${seat.num}</span>
                        </div>
                        <button type="button" class="cart-seat-remove-btn" onclick="window.removeSeatFromCart('${seatId}')" title="Retirer ce siège">&times;</button>
                    </div>
                    <div class="cart-seat-item-bottom">
                        <span class="cart-seat-category-tag">${seat.category}</span>
                        <strong class="cart-seat-price">${new Intl.NumberFormat('fr-FR').format(seat.price)} FCFA</strong>
                    </div>
                </div>
            `;
        });

        if (seatsListEl) seatsListEl.innerHTML = itemsHTML;

        const total = subtotal + totalFees;

        if (subtotalLabelEl) subtotalLabelEl.textContent = `Sous-total (${count} billet${count > 1 ? 's' : ''})`;
        if (subtotalEl) subtotalEl.textContent = `${new Intl.NumberFormat('fr-FR').format(subtotal)} FCFA`;
        if (feesEl) feesEl.textContent = `${new Intl.NumberFormat('fr-FR').format(totalFees)} FCFA`;
        if (totalEl) totalEl.textContent = `${new Intl.NumberFormat('fr-FR').format(total)} FCFA`;
        if (payBtn) payBtn.disabled = false;
    }

    // Gestion du Zoom sur le plan SVG
    window.handlePlanZoom = function (direction) {
        const svg = document.getElementById('sbAmphiSvg');
        if (!svg) return;

        if (direction === 'in') {
            state.zoomLevel = Math.min(1.5, state.zoomLevel + 0.12);
        } else if (direction === 'out') {
            state.zoomLevel = Math.max(0.85, state.zoomLevel - 0.12);
        } else {
            state.zoomLevel = 1.0;
        }

        svg.style.transform = `scale(${state.zoomLevel})`;
    };

    // Soumission du panier vers client/commander.php
    window.proceedToCheckout = function () {
        if (state.selectedSeats.size === 0) return;

        const form = document.getElementById('sbOrderHiddenForm');
        if (!form) return;

        const ticketsInputWrap = document.getElementById('sbHiddenInputsContainer');
        if (!ticketsInputWrap) return;

        ticketsInputWrap.innerHTML = '';

        const countsByTicket = {};
        const seatsByTicket = {};

        state.selectedSeats.forEach((seat) => {
            const tid = seat.ticketId;
            countsByTicket[tid] = (countsByTicket[tid] || 0) + 1;
            if (!seatsByTicket[tid]) seatsByTicket[tid] = [];
            seatsByTicket[tid].push(seat.id);
        });

        Object.keys(countsByTicket).forEach((tid) => {
            const count = countsByTicket[tid];
            const inpQty = document.createElement('input');
            inpQty.type = 'hidden';
            inpQty.name = `tickets[${tid}]`;
            inpQty.value = count;
            ticketsInputWrap.appendChild(inpQty);

            const seats = seatsByTicket[tid] || [];
            seats.forEach((seatCode) => {
                const inpPlace = document.createElement('input');
                inpPlace.type = 'hidden';
                inpPlace.name = `places[${tid}][]`;
                inpPlace.value = seatCode;
                ticketsInputWrap.appendChild(inpPlace);
            });
        });

        // Détection de l'utilisateur connecté
        const cfg = window.BOOKING_CONFIG || {};
        const userNom = (cfg.user_nom || window.BOOKING_USER_NOM || '').trim();
        const userTel = (cfg.user_tel || window.BOOKING_USER_TEL || '').trim();

        if (cfg.has_user && userNom && userTel) {
            // Utilisateur connecté : soumission directe
            form.submit();
        } else {
            // Utilisateur invité : afficher la modale épurée
            const modal = document.getElementById('sbCheckoutModal');
            if (modal) {
                modal.style.display = 'flex';
                const inputNom = document.getElementById('sbModalClientNom');
                if (inputNom) inputNom.focus();
            } else {
                form.submit();
            }
        }
    };

    // Fermeture de la modale invité
    window.closeCheckoutModal = function () {
        const modal = document.getElementById('sbCheckoutModal');
        if (modal) modal.style.display = 'none';
    };

    // Validation du formulaire de la modale invité
    window.submitGuestCheckout = function (e) {
        if (e) e.preventDefault();
        const nom = document.getElementById('sbModalClientNom')?.value.trim();
        const tel = document.getElementById('sbModalClientTel')?.value.trim();
        const email = document.getElementById('sbModalClientEmail')?.value.trim();

        if (!nom) {
            alert('Veuillez renseigner votre nom complet.');
            return false;
        }
        if (!tel) {
            alert('Veuillez renseigner votre numéro de téléphone.');
            return false;
        }

        const form = document.getElementById('sbOrderHiddenForm');
        if (!form) return false;

        document.getElementById('sbHiddenClientNom').value = nom;
        document.getElementById('sbHiddenClientTel').value = tel;
        document.getElementById('sbHiddenClientEmail').value = email || '';

        form.submit();
        return false;
    };

    // Initialisation au chargement du DOM
    document.addEventListener('DOMContentLoaded', () => {
        renderAmphitheatreSVG();
        renderCart();

        // Sélection par défaut des 2 places A12 et A13 pour refléter la maquette
        setTimeout(() => {
            if (state.selectedSeats.size === 0) {
                window.handleSeatClick('A12');
                window.handleSeatClick('A13');
            }
        }, 120);
    });

})();
