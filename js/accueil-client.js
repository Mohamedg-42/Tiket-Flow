// Places sélectionnées par tarif : { ticketId: [{id, numero}, ...] }
let selectedSeats = {};

function openEventModal(button) {
    if (!button) {
        console.warn("openEventModal: aucun élément bouton fourni");
        return;
    }

    const clientEventModal = document.getElementById('clientEventModal');
    const clientOrderForm = document.getElementById('clientOrderForm');
    const tiersContainer = document.getElementById('ticket-tiers-container');

    if (!clientEventModal) {
        console.error("clientEventModal introuvable dans le DOM");
        return;
    }

    const eventId = button.getAttribute('data-event-id') || button.dataset.eventId || '';

    // Détection PC / Desktop (> 768px) : redirection vers la page dédiée complète
    if (window.innerWidth > 768 && eventId) {
        window.location.href = 'evenement.php?id=' + encodeURIComponent(eventId) + '#billets';
        return;
    }

    let eventName = button.getAttribute('data-event-name') || button.dataset.eventName || '';
    let eventDate = button.getAttribute('data-event-date') || button.dataset.eventDate || '';
    let eventTime = button.getAttribute('data-event-time') || button.dataset.eventTime || '';
    let eventPlace = button.getAttribute('data-event-place') || button.dataset.eventPlace || '';
    let eventDesc = button.getAttribute('data-event-desc') || button.dataset.eventDesc || '';
    let eventCapacity = Number(button.getAttribute('data-event-capacity') || button.dataset.eventCapacity) || 0;
    let eventStock = Number(button.getAttribute('data-event-stock') || button.dataset.eventStock) || 0;
    let hasSalle3D = (button.getAttribute('data-has-salle-3d') === '1' || button.dataset.hasSalle3d === '1');

    if (eventId && window.EVENTS_DATA && Array.isArray(window.EVENTS_DATA)) {
        const evFound = window.EVENTS_DATA.find(e => Number(e.id) === Number(eventId));
        if (evFound) {
            if (!eventName) eventName = evFound.nom || 'Événement';
            if (!eventDate && evFound.date_evenement) eventDate = new Date(evFound.date_evenement).toLocaleDateString('fr-FR');
            if (!eventTime && evFound.heure) eventTime = evFound.heure.substring(0, 5);
            if (!eventPlace) eventPlace = evFound.lieu || '';
            if (!eventDesc) eventDesc = evFound.description || '';
        }
    }

    const nameEl = document.getElementById('clientModalEventName');
    if (nameEl) nameEl.textContent = eventName;

    const dateEl = document.getElementById('clientModalDate');
    if (dateEl) dateEl.textContent = eventDate;

    const timeEl = document.getElementById('clientModalTime');
    if (timeEl) timeEl.textContent = eventTime;

    const placeEl = document.getElementById('clientModalPlace');
    if (placeEl) placeEl.textContent = eventPlace;

    // Description complète
    const descEl = document.getElementById('clientModalDesc');
    if (descEl) {
        if (eventDesc) {
            descEl.textContent = eventDesc;
            descEl.style.display = 'block';
        } else {
            descEl.style.display = 'none';
        }
    }

    // Places disponibles sur capacité totale
    const capEl = document.getElementById('clientModalCapacity');
    if (capEl) capEl.textContent = eventStock + ' place(s) disponible(s) sur ' + eventCapacity;

    // Réinitialisation des sélections de places
    selectedSeats = {};
    const seatHidden = document.getElementById('seat-hidden-inputs');
    if (seatHidden) seatHidden.innerHTML = '';

    const idInput = document.getElementById('clientModalEventId');
    if (idInput) idInput.value = eventId;
    if (clientOrderForm) clientOrderForm.action = 'commander.php?id=' + eventId;

    // Génération de la liste de TOUS les tarifs disponibles pour cet événement
    if (tiersContainer) {
        tiersContainer.innerHTML = '';
        let options = [];
        try {
            const raw = button.getAttribute('data-ticket-options') || button.dataset.ticketOptions || '[]';
            options = typeof raw === 'string' ? JSON.parse(raw) : (Array.isArray(raw) ? raw : []);
        } catch (e) {
            console.error("Erreur de décodage des tarifs JSON:", e);
            options = [];
        }

        if ((!options || options.length === 0) && eventId && window.TICKETS_BY_EVENT && window.TICKETS_BY_EVENT[Number(eventId)]) {
            options = window.TICKETS_BY_EVENT[Number(eventId)];
        }

        if (options.length === 0) {
            tiersContainer.innerHTML = '<div style="color: var(--danger); padding: 1rem; text-align: center;">Aucun billet disponible pour cet événement.</div>';
        }

        options.forEach(function (ticket, index) {
            const stock = Math.max(0, Number(ticket.quantite) - Number(ticket.quantite_vendue || 0));
            const isSoldOut = (stock <= 0);
            const fraisPlace = Number(ticket.frais_place) > 0 ? Number(ticket.frais_place) : 0;
            const placesLibres = Array.isArray(ticket.places) ? ticket.places : [];
            const placesChoisies = Number(ticket.places_choisies || 0);

            let seatChoiceHtml = '';
            if (!isSoldOut) {
                seatChoiceHtml = `
                <div class="seat-choice-block" id="seat_block_${ticket.id}">
                    <label class="seat-choice-label">
                        <input type="checkbox" id="seat_toggle_${ticket.id}" onchange="toggleSeatMap(${ticket.id})">
                        <div class="seat-choice-text-wrap">
                            <div class="seat-choice-title-row">
                                <span class="seat-choice-3d-badge"><i class="fa-solid fa-cube"></i> Vue 3D</span>
                                <strong class="seat-choice-title">Choisir ma place sur le Rendu 3D de la salle</strong>
                                ${fraisPlace > 0 ? `<span class="seat-choice-fee">+${Number(fraisPlace).toLocaleString('fr-FR')} FCFA</span>` : ''}
                            </div>
                            <small class="seat-choice-desc">
                                Cochez cette case pour visualiser la scène en 3D immersive et choisir précisément vos fauteuils.
                            </small>
                        </div>
                    </label>

                    <!-- VUE DE SCÈNE INTERACTIVE (RENDU 3D) -->
                    <div class="scene-view-card" id="scene_view_${ticket.id}" hidden>
                        <div class="scene-stage-banner">
                            <div class="scene-stage-podium">
                                <i class="fa-solid fa-masks-theater"></i> SCÈNE PRINCIPALE / PODIUM
                            </div>
                            <div class="scene-stage-sub">
                                <i class="fa-solid fa-arrow-up"></i> Orientation face à la scène
                            </div>
                        </div>

                        <button type="button" class="btn-scene-interactive" onclick="openClient3DSeating(${ticket.id})" title="Ouvrir le Rendu 3D de la salle">
                            <div class="btn-scene-left">
                                <span class="btn-scene-icon-box">
                                    <i class="fa-solid fa-cube"></i>
                                </span>
                                <div class="btn-scene-labels">
                                    <span class="btn-scene-main-text">Ouvrir le Rendu 3D Immersif</span>
                                    <span class="btn-scene-sub-text">Immersion temps réel · Cliquez pour sélectionner vos sièges</span>
                                </div>
                            </div>
                            <span class="scene-tag-badge">
                                <i class="fa-solid fa-cube"></i> Rendu 3D
                            </span>
                        </button>

                        <div class="scene-selected-summary" id="scene_summary_${ticket.id}">
                            <div style="color: #94A3B8; font-size: 0.76rem; text-align: center;">
                                <i class="fa-solid fa-hand-pointer" style="color: #FF4A0D; margin-right: 4px;"></i> Cliquez sur le bouton <strong>Rendu 3D</strong> ci-dessus pour sélectionner vos places face à la scène.
                            </div>
                        </div>
                    </div>
                </div>`;
            }

            const row = document.createElement('div');
            row.className = 'ticket-tier-row';
            row.innerHTML = `
                <div class="ticket-tier-info">
                    <strong>${ticket.nom}</strong>
                    <div style="display: flex; align-items: center; gap: 0.4rem 0.65rem; flex-wrap: wrap; margin-top: 3px;">
                        <span style="color: var(--primary); font-weight: 800; font-size: 0.95rem;">${Number(ticket.prix).toLocaleString('fr-FR')} FCFA</span>
                        <small style="color: ${isSoldOut ? 'var(--danger)' : '#FF4A0D'}; font-weight: 600;">
                            ${isSoldOut ? '• Épuisé' : `• ${stock} restante(s)`}
                        </small>
                    </div>
                </div>

                <div class="ticket-qty-control">
                    <button type="button" class="ticket-qty-btn" onclick="changeQty(${ticket.id}, -1)" ${isSoldOut ? 'disabled' : ''}>-</button>
                    <input type="number" name="tickets[${ticket.id}]" id="qty_input_${ticket.id}" class="ticket-qty-input"
                           min="0" max="${Math.min(10, stock)}" value="${index === 0 && !isSoldOut ? 1 : 0}"
                           data-prix="${ticket.prix}" data-frais-place="${fraisPlace}" data-stock="${stock}"
                           oninput="updateMultiTicketTotal()" ${isSoldOut ? 'disabled' : ''}>
                    <button type="button" class="ticket-qty-btn" onclick="changeQty(${ticket.id}, 1)" ${isSoldOut ? 'disabled' : ''}>+</button>
                </div>

                ${seatChoiceHtml}
            `;
            tiersContainer.appendChild(row);
        });
    }

    updateMultiTicketTotal();
    if (clientEventModal) {
        clientEventModal.hidden = false;
        clientEventModal.style.display = 'flex';
    }
    document.body.classList.add('modal-open');
}

function changeQty(ticketId, delta) {
    const input = document.getElementById('qty_input_' + ticketId);
    if (!input || input.disabled) return;

    let val = Number(input.value) || 0;
    const max = Number(input.max) || 10;
    const min = Number(input.min) || 0;

    val = Math.min(max, Math.max(min, val + delta));
    input.value = val;
    updateMultiTicketTotal();
}

/* ===== Vue de Scène & Choix de place ===== */
function toggleSeatMap(ticketId, forcedEventId = null) {
    const sceneView = document.getElementById('scene_view_' + ticketId);
    const toggleCb = document.getElementById('seat_toggle_' + ticketId);
    const qtyInput = document.getElementById('qty_input_' + ticketId) || document.getElementById('qty-input-' + ticketId);
    if (!sceneView || !toggleCb) return;

    const opening = toggleCb.checked;
    sceneView.hidden = !opening;

    // Détermination robuste de l'ID événement
    const urlParams = new URLSearchParams(window.location.search);
    const eventId = forcedEventId 
                 || urlParams.get('id')
                 || urlParams.get('event_id')
                 || document.getElementById('clientModalEventId')?.value 
                 || document.querySelector('input[name="event_id"]')?.value 
                 || document.getElementById('event_id')?.value
                 || (typeof window.EV_EVENT_ID !== 'undefined' ? window.EV_EVENT_ID : null);

    if (opening) {
        // Mode "place au choix sur la vue de scène"
        if (qtyInput) {
            qtyInput.dataset.seatMode = '1';
            if (Number(qtyInput.value) <= 0) qtyInput.value = 1;
        }
        // Ouvre directement la vue de scène immersive 3D pour ce billet
        openClient3DSeating(ticketId, eventId);
    } else {
        // Fermeture de la vue de scène : on efface la sélection pour ce tarif
        clearSeatSelection(ticketId);
        if (qtyInput) {
            qtyInput.dataset.seatMode = '0';
            qtyInput.disabled = false;
            qtyInput.readOnly = false;
            qtyInput.style.pointerEvents = '';
            qtyInput.style.opacity = '';
            qtyInput.value = Math.min(1, Number(qtyInput.max) || 1);
        }
    }
    syncTierSeats(ticketId);
    try {
        if (typeof updateMultiTicketTotal === 'function') updateMultiTicketTotal();
    } catch (_) {}
    try {
        if (typeof calculateEventTotal === 'function') calculateEventTotal();
    } catch (_) {}
}

/* ===== Choix Direct de Places Numérotées (Espaces Non Répertoriés) ===== */
function toggleDirectNumberedSeats(ticketId, maxSeats) {
    const card = document.getElementById('direct_seats_card_' + ticketId);
    const toggleCb = document.getElementById('seat_toggle_' + ticketId);
    const qtyInput = document.getElementById('qty_input_' + ticketId);
    const grid = document.getElementById('direct_seats_grid_' + ticketId);
    if (!card || !toggleCb) return;

    const opening = toggleCb.checked;
    card.hidden = !opening;

    if (opening) {
        if (qtyInput) {
            qtyInput.dataset.seatMode = '1';
            qtyInput.disabled = true;
        }
        if (grid && grid.children.length === 0) {
            const total = Math.max(1, Math.min(Number(maxSeats) || 10, 500));
            for (let i = 1; i <= total; i++) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'direct-seat-btn';
                btn.id = 'seat_btn_' + ticketId + '_' + i;
                btn.textContent = 'N° ' + i;
                btn.onclick = function () {
                    toggleSingleDirectSeat(ticketId, i, Number(qtyInput ? qtyInput.max : 10) || 10);
                };
                grid.appendChild(btn);
            }
        }
    } else {
        clearSeatSelection(ticketId);
        if (grid) {
            grid.querySelectorAll('.direct-seat-btn.selected').forEach(function (b) {
                b.classList.remove('selected');
            });
        }
        if (qtyInput) {
            qtyInput.dataset.seatMode = '0';
            qtyInput.disabled = false;
            qtyInput.value = Math.min(1, Number(qtyInput.max) || 1);
        }
        updateDirectSeatsCounter(ticketId);
        syncTierSeats(ticketId);
        updateMultiTicketTotal();
    }
}

function toggleSingleDirectSeat(ticketId, num, maxAllowed) {
    if (!selectedSeats[ticketId]) selectedSeats[ticketId] = [];
    const seatName = 'Place ' + num;
    const idx = selectedSeats[ticketId].findIndex(function (s) { return s.numero === seatName; });
    const btn = document.getElementById('seat_btn_' + ticketId + '_' + num);

    if (idx >= 0) {
        selectedSeats[ticketId].splice(idx, 1);
        if (btn) btn.classList.remove('selected');
    } else {
        if (selectedSeats[ticketId].length >= maxAllowed) {
            alert("Vous pouvez sélectionner au maximum " + maxAllowed + " place(s).");
            return;
        }
        selectedSeats[ticketId].push({
            id: 'seat_' + ticketId + '_' + num,
            numero: seatName,
            prix: 0
        });
        if (btn) btn.classList.add('selected');
    }

    const qtyInput = document.getElementById('qty_input_' + ticketId);
    if (qtyInput) {
        qtyInput.value = selectedSeats[ticketId].length;
    }
    updateDirectSeatsCounter(ticketId);
    syncTierSeats(ticketId);
    updateMultiTicketTotal();
}

function updateDirectSeatsCounter(ticketId) {
    const counter = document.getElementById('direct_seats_counter_' + ticketId);
    const count = (selectedSeats[ticketId] || []).length;
    if (counter) {
        counter.textContent = count + ' place(s) sélectionnée(s)';
    }
}

function clearSeatSelection(ticketId) {
    selectedSeats[ticketId] = [];
}

function syncTierSeats(ticketId) {
    const seats = selectedSeats[ticketId] || [];
    const qtyInput = document.getElementById('qty_input_' + ticketId) || document.getElementById('qty-input-' + ticketId);
    const summary = document.getElementById('scene_summary_' + ticketId);
    const container = document.getElementById('seat-hidden-inputs');

    // Champs cachés envoyés au serveur (places[ticketId][] = id)
    if (container) {
        container.querySelectorAll('input[data-tier="' + ticketId + '"]').forEach(function (i) { i.remove(); });
        seats.forEach(function (s) {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'places[' + ticketId + '][]';
            inp.value = s.id;
            inp.setAttribute('data-tier', ticketId);
            container.appendChild(inp);
        });
    }

    if (qtyInput && qtyInput.dataset.seatMode === '1') {
        qtyInput.value = seats.length;
    }
    if (summary) {
        if (seats.length > 0) {
            summary.innerHTML = `
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-weight: 800; color: #FF4A0D; font-size: 0.82rem;">
                            <i class="fa-solid fa-cube"></i> ${seats.length} place(s) Vue Scène 3D :
                        </span>
                        <button type="button" onclick="openClient3DSeating(${ticketId})" style="background: rgba(255, 74, 13, 0.15); border: 1px solid rgba(255, 74, 13, 0.4); color: #FF4A0D; font-size: 0.74rem; font-weight: 700; border-radius: 6px; cursor: pointer; padding: 3px 8px;">
                            <i class="fa-solid fa-pen-to-square"></i> Modifier sur la scène 3D
                        </button>
                    </div>
                    <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                        ${seats.map(s => `<span style="background: #0F172A; border: 1px solid #334155; color: #F8FAFC; font-size: 0.74rem; padding: 4px 9px; border-radius: 6px; font-weight: 800; display: inline-flex; align-items: center; gap: 4px;"><i class="fa-solid fa-chair" style="color: #FF4A0D; font-size: 0.7rem;"></i> Place ${s.numero}</span>`).join('')}
                    </div>
                `;
            summary.style.display = 'block';
        } else {
            summary.innerHTML = `
                    <div style="color: #94A3B8; font-size: 0.76rem; text-align: center;">
                        <i class="fa-solid fa-hand-pointer" style="color: #FF4A0D; margin-right: 4px;"></i> Cliquez ci-dessus sur <strong>Rendu 3D</strong> pour sélectionner vos places directement sur la scène.
                    </div>
                `;
            summary.style.display = 'block';
        }
    }
}

function updateMultiTicketTotal() {
    const inputs = document.querySelectorAll('.ticket-qty-input:not(:disabled)');
    let totalCount = 0;
    let totalPrice = 0;

    inputs.forEach(function (input) {
        const qty = Number(input.value) || 0;
        const prix = Number(input.dataset.prix) || 0;

        totalCount += qty;
        totalPrice += (qty * prix);
    });

    // Tarifs en mode "place au choix" : prix du billet + supplément par place choisie
    if (typeof selectedSeats === 'object' && selectedSeats) {
        Object.keys(selectedSeats).forEach(function (ticketId) {
            const qtyInput = document.getElementById('qty_input_' + ticketId) || document.getElementById('qty-input-' + ticketId);
            if (!qtyInput || qtyInput.dataset.seatMode !== '1') return;
            const seats = selectedSeats[ticketId] || [];
            const prix = Number(qtyInput.dataset.prix) || Number(qtyInput.dataset.price) || 0;
            const frais = Number(qtyInput.dataset.fraisPlace) > 0 ? Number(qtyInput.dataset.fraisPlace) : 
                         (Number(qtyInput.dataset.fraisPlace) || 1000);
            totalCount += seats.length;
            totalPrice += seats.length * (prix + frais);
        });
    }

    const countEl = document.getElementById('clientModalTicketsCount');
    if (countEl) countEl.textContent = totalCount + ' place(s) sélectionnée(s)';
    const totalEl = document.getElementById('clientModalTotal');
    if (totalEl) totalEl.textContent = totalPrice.toLocaleString('fr-FR') + ' FCFA';

    const submitBtn = document.getElementById('btnSubmitOrder');
    if (submitBtn) {
        if (totalCount <= 0) {
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.6';
        } else {
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
        }
    }
}

/* ===== Vote pour un événement ===== */
async function toggleVote(e, btn) {
    e.stopPropagation();
    const eventId = btn.dataset.eventId;
    try {
        const res = await fetch('vote-event.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'event_id=' + encodeURIComponent(eventId)
        });
        const data = await res.json();
        if (data.error) {
            alert(data.error);
            return;
        }
        // VOTE PAYANT : le promoteur a fixé un prix → ouverture de la modale de paiement
        if (data.needs_payment) {
            openVoteModal(eventId, data.event_nom, data.prix, data.candidats || [], data.type_vote, data.vote_question);
            return;
        }
        // Phase 2 validée : redirection vers la page de paiement Mobile Money
        if (data.redirect) {
            window.location.href = data.redirect;
            return;
        }
        // VOTE GRATUIT : bascule voter / voté
        btn.classList.toggle('voted', data.voted);
        btn.innerHTML = data.voted
            ? '<i class="fa-solid fa-thumbs-up"></i> Voté'
            : '<i class="fa-solid fa-thumbs-up"></i> Vote';
        // Met à jour le compteur de votes affiché dans la carte (sans recharger la page)
        const card = btn.closest('.event-card-item, .vote-item');
        if (card) {
            const counter = card.querySelector('.vote-counter');
            if (counter) {
                counter.innerHTML = '<i class="fa-solid fa-star" style="color:#FF4A0D;"></i> '
                    + data.votes + (data.votes > 1 ? ' votes' : ' vote');
            }
        }
    } catch (err) {
        alert("Impossible d'enregistrer le vote. Exécutez config/migration-votes-cotisations.sql pour créer la table event_votes.");
    }
}

/* ===== Modale de paiement d'un vote payant (avec choix multiples) ===== */
const voteModal = document.getElementById('voteModal');
let voteModalEventId = null;
let currentVotePrix = 0;
let currentVoteCandidats = [];

function openVoteModal(eventId, eventNom, prix, candidats, typeVote, voteQuestion) {
    voteModalEventId = eventId;
    currentVotePrix = Number(prix) || 0;
    currentVoteCandidats = Array.isArray(candidats) ? candidats : [];

    const titleEl = document.getElementById('voteModalEventName');
    if (titleEl) {
        if (typeVote === 'realisation_evenement' && voteQuestion) {
            titleEl.innerHTML = `<span style="display:block; color:var(--primary); font-size:0.85rem; font-weight:800; text-transform:uppercase;">Vote de Réalisation : ${eventNom}</span>` +
                `<strong style="color:var(--navy); font-size:1.05rem; display:block; margin-top:2px;">« ${voteQuestion} »</strong>`;
        } else {
            titleEl.textContent = eventNom || '';
        }
    }
    document.getElementById('voteModalPrix').textContent = currentVotePrix.toLocaleString('fr-FR') + ' FCFA';

    const candWrapper = document.getElementById('voteModalCandidatsWrapper');
    const candList = document.getElementById('voteModalCandidatsList');

    if (currentVoteCandidats.length > 0) {
        candWrapper.style.display = 'block';
        candList.innerHTML = '';

        currentVoteCandidats.forEach((c, idx) => {
            const card = document.createElement('div');
            card.className = 'candidat-choice-card selected'; // Coche par défaut pour guider
            card.dataset.id = c.id;

            const photoSrc = c.photo || 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=400&q=80';
            const descText = c.description ? c.description : 'Option de vote pour cet événement.';

            card.innerHTML = `
                    <input type="checkbox" class="vote-candidat-cb candidat-checkbox" value="${c.id}" checked>
                    <img src="${photoSrc}" alt="${c.nom}" class="candidat-choice-photo">
                    <div class="candidat-choice-info">
                        <div class="candidat-choice-nom">
                            <span>${c.nom}</span>
                            <span class="candidat-badge-selected"><i class="fa-solid fa-check"></i> Sélectionné</span>
                        </div>
                        <p class="candidat-choice-desc">${descText}</p>
                    </div>
                `;

            // Clic sur l'ensemble de la carte
            card.addEventListener('click', function (e) {
                if (e.target.tagName !== 'INPUT') {
                    const cb = card.querySelector('.vote-candidat-cb');
                    cb.checked = !cb.checked;
                }
                card.classList.toggle('selected', card.querySelector('.vote-candidat-cb').checked);
                updateVoteModalTotal();
            });

            const cb = card.querySelector('.vote-candidat-cb');
            cb.addEventListener('change', function () {
                card.classList.toggle('selected', cb.checked);
                updateVoteModalTotal();
            });

            candList.appendChild(card);
        });
    } else {
        candWrapper.style.display = 'none';
        candList.innerHTML = '';
    }

    updateVoteModalTotal();
    voteModal.hidden = false;
    document.body.classList.add('modal-open');
}

function updateVoteModalTotal() {
    const selectedCbs = document.querySelectorAll('.vote-candidat-cb:checked');
    const count = selectedCbs.length;

    if (currentVoteCandidats.length > 0) {
        document.getElementById('voteModalChoicesCount').textContent = count + ' sélectionné(s)';
        const total = count * currentVotePrix;
        document.getElementById('voteModalTotalDetail').textContent = count + ' choix × ' + currentVotePrix.toLocaleString('fr-FR') + ' F';
        document.getElementById('voteModalTotalAmount').textContent = total.toLocaleString('fr-FR') + ' FCFA';

        const submitBtn = document.getElementById('votePaySubmit');
        if (count === 0) {
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.6';
            submitBtn.innerHTML = '<i class="fa-solid fa-hand-pointer"></i> Cochez au moins 1 choix';
        } else {
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
            submitBtn.innerHTML = '<i class="fa-solid fa-credit-card"></i> Continuer vers le paiement (' + count + ' vote' + (count > 1 ? 's' : '') + ')';
        }
    } else {
        document.getElementById('voteModalTotalDetail').textContent = '1 vote pour l\'événement';
        document.getElementById('voteModalTotalAmount').textContent = currentVotePrix.toLocaleString('fr-FR') + ' FCFA';
        const submitBtn = document.getElementById('votePaySubmit');
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
        submitBtn.innerHTML = '<i class="fa-solid fa-credit-card"></i> Continuer vers le paiement';
    }
}

function closeVoteModal() {
    voteModal.hidden = true;
    document.body.classList.remove('modal-open');
    voteModalEventId = null;
    currentVoteCandidats = [];
}

async function submitVotePayment(e) {
    e.preventDefault();
    if (!voteModalEventId) return false;

    const submitBtn = document.getElementById('votePaySubmit');
    submitBtn.disabled = true;
    submitBtn.style.opacity = '0.6';

    try {
        const selectedCbs = Array.from(document.querySelectorAll('.vote-candidat-cb:checked')).map(cb => cb.value);

        if (currentVoteCandidats.length > 0 && selectedCbs.length === 0) {
            alert('Veuillez sélectionner au moins un choix.');
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
            return false;
        }

        let bodyParams = 'event_id=' + encodeURIComponent(voteModalEventId) + '&phase=2';
        if (selectedCbs.length > 0) {
            bodyParams += '&candidat_ids=' + encodeURIComponent(selectedCbs.join(','));
        }

        const res = await fetch('vote-event.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: bodyParams
        });
        const data = await res.json();
        if (data.redirect) {
            window.location.href = data.redirect;
            return false;
        }
        alert(data.error || 'Une erreur est survenue, veuillez réessayer.');
    } catch (err) {
        alert('Erreur réseau lors de la création du paiement.');
    }
    submitBtn.disabled = false;
    submitBtn.style.opacity = '1';
    return false;
}

// Fermeture de la modale de vote par clic sur le fond
if (typeof voteModal !== 'undefined' && voteModal) {
    voteModal.addEventListener('click', function (ev) {
        if (ev.target === voteModal) closeVoteModal();
    });
}

/* ===== Montants rapides de cotisation ===== */
function setCotisation(montant) {
    const input = document.getElementById('cot_montant');
    if (input) input.value = montant;
}

/* ===== Modale de contribution à une campagne ===== */
const cotisationModal = document.getElementById('cotisationModal');

function openCotisationModal(button) {
    if (!cotisationModal) return;
    document.getElementById('cotCampagneId').value = button.dataset.campagneId || '';
    document.getElementById('cotisationModalCampagneName').textContent = button.dataset.campagneTitre || 'Contribution générale';

    // Motivation complète du créateur de la campagne (texte intégral)
    const motivationEl = document.getElementById('cotisationModalMotivation');
    const motivation = button.dataset.campagneMotivation || '';
    if (motivation) {
        motivationEl.textContent = motivation;
        motivationEl.style.display = 'block';
    } else {
        motivationEl.textContent = '';
        motivationEl.style.display = 'none';
    }

    cotisationModal.hidden = false;
    document.body.classList.add('modal-open');
}

function closeCotisationModal() {
    if (!cotisationModal) return;
    cotisationModal.hidden = true;
    document.body.classList.remove('modal-open');
}

if (cotisationModal) {
    cotisationModal.addEventListener('click', function (e) {
        if (e.target === cotisationModal) closeCotisationModal();
    });
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && cotisationModal && !cotisationModal.hidden) closeCotisationModal();
});

/* ===== Description rétractable (Voir plus / Voir moins) ===== */
function toggleDesc(eventId, btn) {
    const p = document.getElementById('desc_' + eventId);
    if (!p) return;

    const full = p.dataset.full || '';
    const short = p.dataset.short || '';
    const expanded = (p.dataset.expanded === '1');

    if (expanded) {
        p.textContent = short;
        p.dataset.expanded = '0';
        btn.innerHTML = 'Voir plus <i class="fa-solid fa-chevron-down"></i>';
    } else {
        p.textContent = full;
        p.dataset.expanded = '1';
        btn.innerHTML = 'Voir moins <i class="fa-solid fa-chevron-up"></i>';
    }
}

/* ===== Like d'un événement (n'ouvre pas la modale) ===== */
async function toggleLike(e, btn) {
    e.stopPropagation();
    const eventId = btn.dataset.eventId;
    try {
        const res = await fetch('like-event.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'event_id=' + encodeURIComponent(eventId)
        });
        const data = await res.json();
        if (data.error) {
            alert(data.error);
            return;
        }
        btn.classList.toggle('liked', data.liked);
        btn.querySelector('i').className = data.liked ? 'fa-solid fa-heart' : 'fa-regular fa-heart';
        btn.querySelector('.like-count').textContent = data.likes;
    } catch (err) {
        alert("Impossible d'enregistrer le like. Exécutez config/migration-likes.sql pour créer la table event_likes.");
    }
}

function closeEventModal() {
    const modal = document.getElementById('clientEventModal');
    if (modal) {
        modal.hidden = true;
        modal.style.display = 'none';
    }
    document.body.classList.remove('modal-open');
}

document.addEventListener('click', function (e) {
    const modal = document.getElementById('clientEventModal');
    if (modal && e.target === modal) closeEventModal();
});

document.addEventListener('keydown', function (e) {
    const modal = document.getElementById('clientEventModal');
    if (e.key === 'Escape' && modal && !modal.hidden) closeEventModal();
});

/* ==============================================================================
   RENDU 3D CLIENT & SÉLECTION DE PLACES SELON LE TARIF
   ============================================================================== */
let client3DEngine = null;
let currentEvent3DData = null;

async function openClient3DSeating(targetTicketId = null, forcedEventId = null) {
    const urlParams = new URLSearchParams(window.location.search);
    const eventId = forcedEventId 
                 || urlParams.get('id')
                 || urlParams.get('event_id')
                 || document.getElementById('clientModalEventId')?.value 
                 || document.querySelector('input[name="event_id"]')?.value 
                 || document.getElementById('event_id')?.value
                 || (typeof window.EV_EVENT_ID !== 'undefined' ? window.EV_EVENT_ID : null);
    const eventName = document.getElementById('clientModalEventName')?.textContent 
                    || document.querySelector('.event-title')?.textContent 
                    || document.querySelector('h1')?.textContent 
                    || 'Événement';
    const eventPlace = document.getElementById('clientModalPlace')?.textContent 
                    || document.querySelector('.event-metric-box strong[title]')?.textContent 
                    || document.querySelector('.event-place-name')?.textContent
                    || '';

    if (!eventId) {
        console.warn('openClient3DSeating: Aucun ID événement trouvé');
        return;
    }

    const titleEl = document.getElementById('client3DEventTitle');
    if (titleEl) titleEl.textContent = eventName.trim();
    const subEl = document.getElementById('client3DVenueSubtitle');
    if (subEl) subEl.textContent = eventPlace.trim() ? ('Lieu : ' + eventPlace.trim() + ' • Orientation interactive & choix de places') : 'Orientation interactive & choix de places';

    const modal3D = document.getElementById('client3DSeatingModal');
    if (!modal3D) {
        console.error('Modal #client3DSeatingModal introuvable');
        return;
    }
    modal3D.hidden = false;
    modal3D.style.display = 'flex';
    document.body.classList.add('modal-open');

    const canvas = document.getElementById('client3DCanvas');
    const EngineClass = window.TikéliVenue3D || window.TikeliVenue3D || window.EventiaVenue3D;
    if (!EngineClass) {
        console.error('TikéliVenue3D introuvable dans window');
        alert('Initialisation de la vue 3D en cours... Veuillez patienter.');
        return;
    }

    if (!client3DEngine) {
        client3DEngine = new EngineClass(canvas, {
            readOnly: false,
            maxSeats: 10,
            onSeatSelect: (seat, all) => {
                updateClient3DSidebar(all);
            },
            onSeatDeselect: (seat, all) => {
                updateClient3DSidebar(all);
            },
            onHoverSeat: (seat) => {
                if (seat) {
                    const sightBox = document.getElementById('client3DSightlineBox');
                    const sightDesc = document.getElementById('client3DSightlineDesc');
                    if (sightBox && sightDesc) {
                        sightBox.style.display = 'block';
                        const p = Number(seat.prix) || 0;
                        const f = Number(seat.frais_place) > 0 ? Number(seat.frais_place) : 1000;
                        sightDesc.innerHTML = `<strong>Place ${seat.code}</strong> (${seat.zone_name})<br>
                            Distance estimée : <strong>${Math.max(6, Math.round(seat.z / 10))} m</strong><br>
                            <span style="color: #FF4A0D; font-weight: 700;">Billet : ${p.toLocaleString('fr-FR')} F + Choix place : ${f.toLocaleString('fr-FR')} F = ${(p + f).toLocaleString('fr-FR')} FCFA</span>`;
                    }
                }
            }
        });
        window.client3DEngine = client3DEngine;
    }

    // Indicateur de chargement immédiat sur la barre de filtres
    const tariffBar = document.getElementById('client3DTariffBar');
    if (tariffBar) {
        tariffBar.innerHTML = '<div style="color: #94A3B8; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px;"><i class="fa-solid fa-spinner fa-spin" style="color: #FF4A0D;"></i> Chargement de la salle 3D...</div>';
    }

    // Chargement des données 3D pour cet événement (avec cache-buster et fallback de chemin)
    const apiBasePath = window.location.pathname.includes('/client/') ? '../ajax/salle_3d_data.php' : 'ajax/salle_3d_data.php';
    const endpointUrl = `${apiBasePath}?event_id=${eventId}&_t=${Date.now()}`;
    const data = await client3DEngine.loadFromEndpoint(endpointUrl);
    if (data && data.success) {
        currentEvent3DData = data;

        // Remplissage du Plan Architectural
        const planContainer = document.getElementById('clientPlanContent');
        if (planContainer) {
            if (data.salle && data.salle.plan_image) {
                planContainer.innerHTML = `
                            <div style="max-width: 800px; margin: 0 auto;">
                                <div style="color: #FF4A0D; font-weight: 700; font-size: 0.9rem; margin-bottom: 0.75rem; text-transform: uppercase;">
                                    <i class="fa-solid fa-map-location-dot"></i> Plan Architectural / Schéma de la salle
                                </div>
                                <img src="../uploads/salles/${data.salle.plan_image}" alt="Plan de la salle" style="max-width: 100%; max-height: 60vh; border-radius: 12px; border: 1px solid #000000; box-shadow: 0 10px 30px rgba(0,0,0,0.5); object-fit: contain;">
                            </div>
                        `;
            } else {
                planContainer.innerHTML = `
                            <div style="color: #94A3B8; padding: 3rem 1rem; text-align: center;">
                                <i class="fa-solid fa-map" style="font-size: 3rem; color: #334155; margin-bottom: 1rem; display: block;"></i>
                                <h4 style="color: #F8FAFC; margin-bottom: 0.5rem; font-size: 1.1rem; font-weight: 700;">Plan Architectural schématique</h4>
                                <p style="font-size: 0.85rem; max-width: 450px; margin: 0 auto 1.25rem; color: #94A3B8;">La disposition des rangées et des zones est directement accessible et explorable dans l'onglet <strong>Rendu 3D</strong>.</p>
                                <button type="button" class="btn-submit" onclick="switchClient3DTab('3d')" style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: auto; padding: 0.65rem 1.4rem; background: #FF4A0D; color: #ffffff; font-size: 0.88rem; font-weight: 800; border-radius: 8px; box-shadow: 0 4px 14px rgba(255, 74, 13, 0.4); border: none; cursor: pointer;">
                                    <i class="fa-solid fa-cube"></i> Ouvrir le Rendu 3D
                                </button>
                            </div>
                        `;
            }
        }

        // Remplissage de la Galerie Photos Réelles
        const photosContainer = document.getElementById('clientPhotosGrid');
        if (photosContainer) {
            photosContainer.innerHTML = '';
            let photosList = [];
            if (data.salle && data.salle.image_principale) {
                photosList.push({ src: data.salle.image_principale, title: 'Façade / Vue Principale' });
            }
            if (data.salle && Array.isArray(data.salle.galerie_photos)) {
                data.salle.galerie_photos.forEach((ph, idx) => {
                    photosList.push({ src: ph, title: `Vue de Salle #${idx + 1}` });
                });
            }

            if (photosList.length > 0) {
                photosList.forEach(item => {
                    const card = document.createElement('div');
                    card.style.cssText = 'background: #1E293B; border: 1px solid #334155; border-radius: 12px; overflow: hidden; box-shadow: 0 6px 16px rgba(0,0,0,0.3);';
                    card.innerHTML = `
                                <img src="../uploads/salles/${item.src}" alt="${item.title}" style="width: 100%; height: 160px; object-fit: cover; display: block;">
                                <div style="padding: 0.6rem 0.8rem; font-size: 0.78rem; font-weight: 700; color: #F8FAFC;">
                                    <i class="fa-solid fa-camera" style="color: #FF4A0D; margin-right: 4px;"></i> ${item.title}
                                </div>
                            `;
                    photosContainer.appendChild(card);
                });
            } else {
                photosContainer.innerHTML = `
                            <div style="grid-column: 1 / -1; text-align: center; color: #737373; padding: 3rem 1rem;">
                                <i class="fa-solid fa-images" style="font-size: 3rem; color: #000000; margin-bottom: 1rem; display: block;"></i>
                                <h4 style="color: #F5F5F5; margin-bottom: 0.5rem;">Visualisation interactive disponible en 3D</h4>
                                <p style="font-size: 0.85rem; max-width: 450px; margin: 0 auto;">Les angles de vue réels sont simulés en temps réel avec le moteur 3D d'Eventia.</p>
                            </div>
                        `;
            }
        }

        // Barre de boutons de filtrage par Tarif
        if (tariffBar) {
            tariffBar.innerHTML = '<button type="button" class="studio-filter-btn active" onclick="filterClient3D(this, \'all\')">Tous les Tarifs</button>';

            if (data.ticket_types && data.ticket_types.length > 0) {
                let targetBtn = null;
                data.ticket_types.forEach(tt => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'studio-filter-btn';
                    btn.dataset.tierId = tt.id;
                    btn.innerHTML = `<span style="font-weight: 800;">${tt.nom}</span> · <span style="color: #FF4A0D;">${Number(tt.prix).toLocaleString('fr-FR')} F</span>`;
                    btn.onclick = () => filterClient3D(btn, tt.id);
                    tariffBar.appendChild(btn);
                    if (targetTicketId && Number(tt.id) === Number(targetTicketId)) {
                        targetBtn = btn;
                    }
                });
                if (targetBtn) {
                    filterClient3D(targetBtn, targetTicketId);
                }
            } else if (data.zones) {
                data.zones.forEach(z => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'studio-filter-btn';
                    btn.innerHTML = `<span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: ${z.couleur || '#FF4A0D'}; margin-right: 4px;"></span> ${z.nom_zone} (${Number(z.tarif_indicatif || 10000).toLocaleString('fr-FR')} F)`;
                    btn.onclick = () => filterClient3D(btn, z.id);
                    tariffBar.appendChild(btn);
                });
            }
        }

        updateClient3DSidebar([]);
        switchClient3DTab('3d');
        if (client3DEngine) {
            client3DEngine.resize();
            client3DEngine.render();
        }
        setTimeout(() => {
            if (client3DEngine) {
                client3DEngine.resize();
                client3DEngine.render();
            }
        }, 50);
        setTimeout(() => {
            if (client3DEngine) {
                client3DEngine.resize();
                client3DEngine.render();
            }
        }, 200);
    } else {
        console.error('Échec chargement données 3D:', data);
        if (tariffBar) {
            tariffBar.innerHTML = '<span style="color: #f87171; font-size: 0.8rem; padding: 4px 8px;"><i class="fa-solid fa-triangle-exclamation"></i> Impossible de charger la configuration de la salle.</span>';
        }
    }
}

function switchClient3DTab(tab) {
    const p3D = document.getElementById('panelClient3D');
    const pPlan = document.getElementById('panelClientPlan');
    const pPhotos = document.getElementById('panelClientPhotos');
    const camCtrls = document.getElementById('client3DCameraControls');

    document.querySelectorAll('#client3DSeatingModal .studio-tab-btn').forEach(b => b.classList.remove('active'));

    if (tab === '3d') {
        if (p3D) p3D.style.display = 'flex';
        if (pPlan) pPlan.style.display = 'none';
        if (pPhotos) pPhotos.style.display = 'none';
        if (camCtrls) camCtrls.style.display = 'flex';
        const btn = document.getElementById('tabBtnClient3D');
        if (btn) btn.classList.add('active');
        if (client3DEngine) {
            client3DEngine.resize();
            client3DEngine.render();
        }
    } else if (tab === 'plan') {
        if (p3D) p3D.style.display = 'none';
        if (pPlan) pPlan.style.display = 'block';
        if (pPhotos) pPhotos.style.display = 'none';
        if (camCtrls) camCtrls.style.display = 'none';
        const btn = document.getElementById('tabBtnClientPlan');
        if (btn) btn.classList.add('active');
    } else if (tab === 'photos') {
        if (p3D) p3D.style.display = 'none';
        if (pPlan) pPlan.style.display = 'none';
        if (pPhotos) pPhotos.style.display = 'block';
        if (camCtrls) camCtrls.style.display = 'none';
        const btn = document.getElementById('tabBtnClientPhotos');
        if (btn) btn.classList.add('active');
    }
}

function filterClient3D(btn, filterId) {
    document.querySelectorAll('#client3DTariffBar .studio-filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    if (client3DEngine) {
        client3DEngine.filterByTariff(filterId);
    }
}

function setClient3DView(view) {
    if (client3DEngine) {
        client3DEngine.setViewPreset(view);
    }
}

function updateClient3DSidebar(seats) {
    const listEl = document.getElementById('client3DSelectedList');
    const badgeEl = document.getElementById('client3DSeatsBadge');
    const subCountEl = document.getElementById('client3DSubCount');
    const totalEl = document.getElementById('client3DTotalAmount');

    if (!listEl) return;

    badgeEl.textContent = seats.length + (seats.length > 1 ? ' places' : ' place');
    subCountEl.textContent = seats.length + (seats.length > 1 ? ' places choisies' : ' place choisie');

    let total = 0;
    seats.forEach(s => {
        const p = Number(s.prix) || 0;
        const f = Number(s.frais_place) > 0 ? Number(s.frais_place) : 1000;
        total += (p + f);
    });
    totalEl.textContent = total.toLocaleString('fr-FR') + ' FCFA';

    if (seats.length === 0) {
        listEl.innerHTML = '<div style="color: #737373; font-size: 0.8rem; font-style: italic; text-align: center; padding: 1.5rem 0;">Cliquez sur les sièges disponibles dans la vue 3D pour les ajouter à votre sélection.</div>';
        return;
    }

    listEl.innerHTML = '';
    seats.forEach(s => {
        const p = Number(s.prix) || 0;
        const f = Number(s.frais_place) > 0 ? Number(s.frais_place) : 1000;
        const item = document.createElement('div');
        item.className = 's3d-selected-seat-item';
        item.style.cssText = 'background: #0f172a; border: 1px solid #1e293b; border-radius: 8px; padding: 0.55rem 0.75rem; display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; animation: candidateSlideIn 0.28s cubic-bezier(0.16, 1, 0.3, 1) both; transition: all 0.2s ease;';
        item.innerHTML = `
                    <div>
                        <strong style="color: #F8FAFC; font-size: 0.82rem;"><i class="fa-solid fa-chair" style="color: #FF4A0D;"></i> Place ${s.code}</strong>
                        <div style="font-size: 0.72rem; color: #94A3B8;">${s.zone_name} • Rang ${s.row}</div>
                        <div style="font-size: 0.68rem; color: #FF4A0D; font-weight: 700;">Choix de place : +${f.toLocaleString('fr-FR')} F</div>
                    </div>
                    <div style="text-align: right;">
                        <div style="color: #FF4A0D; font-weight: 800; font-size: 0.85rem;">${(p + f).toLocaleString('fr-FR')} F</div>
                        <button type="button" onclick="removeClient3DSeat(${s.id})" style="background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.35); color: #f87171; font-size: 0.72rem; font-weight: 700; cursor: pointer; padding: 3px 8px; border-radius: 6px; transition: all 0.2s ease;">Retirer</button>
                    </div>
                `;
        listEl.appendChild(item);
    });
}

function removeClient3DSeat(seatId) {
    if (client3DEngine) {
        const seat = client3DEngine.seats.find(s => s.id === seatId);
        if (seat) {
            client3DEngine.toggleSeatSelection(seat);
        }
    }
}

function applyClient3DSelection() {
    if (!client3DEngine) return;
    const chosenSeats = Array.from(client3DEngine.selectedSeats.values());

    if (chosenSeats.length === 0) {
        alert('Veuillez sélectionner au moins un siège 3D sur le plan.');
        return;
    }

    // Grouper les places par type de billet (ticket_type_id)
    const seatsByTier = {};
    chosenSeats.forEach(s => {
        const tId = s.ticket_type_id;
        seatsByTier[tId] = seatsByTier[tId] || [];
        seatsByTier[tId].push(s);
    });

    // Appliquer dans le formulaire de commande principal
    Object.keys(seatsByTier).forEach(tId => {
        const seats = seatsByTier[tId];
        const qtyInput = document.getElementById('qty_input_' + tId) || document.getElementById('qty-input-' + tId);
        const toggleCb = document.getElementById('seat_toggle_' + tId);
        const sceneView = document.getElementById('scene_view_' + tId);

        if (toggleCb) toggleCb.checked = true;
        if (sceneView) sceneView.hidden = false;

        if (qtyInput) {
            qtyInput.dataset.seatMode = '1';
            // readOnly (pas disabled) pour que la valeur soit incluse dans le POST du formulaire
            qtyInput.readOnly = true;
            qtyInput.style.pointerEvents = 'none';
            qtyInput.style.opacity = '0.7';
            qtyInput.value = seats.length;
        }

        // Enregistrer dans selectedSeats
        selectedSeats[tId] = seats.map(s => ({ id: s.id, numero: s.code }));
        syncTierSeats(tId);
    });

    try { if (typeof updateMultiTicketTotal === 'function') updateMultiTicketTotal(); } catch (_) {}
    try { if (typeof calculateEventTotal === 'function') calculateEventTotal(); } catch (_) {}
    closeClient3DSeating();
}

function closeClient3DSeating() {
    const modal3D = document.getElementById('client3DSeatingModal');
    if (modal3D) {
        modal3D.hidden = true;
        modal3D.style.display = 'none';
    }
    document.body.classList.remove('modal-open');
}

function toggleMobileSeatList() {
    const sidebar = document.getElementById('client3DSidebar');
    const text = document.getElementById('s3dToggleText');
    const icon = document.getElementById('s3dToggleIcon');
    if (!sidebar) return;
    sidebar.classList.toggle('is-collapsed');
    const isCollapsed = sidebar.classList.contains('is-collapsed');
    if (text) text.textContent = isCollapsed ? 'Voir détails' : 'Masquer';
    if (icon) icon.className = isCollapsed ? 'fa-solid fa-chevron-down' : 'fa-solid fa-chevron-up';
    if (client3DEngine) {
        setTimeout(() => {
            client3DEngine.resize();
            client3DEngine.render();
        }, 50);
    }
}

// Réactivité immédiate au redimensionnement d'écran & rotation mobile
window.addEventListener('resize', () => {
    const modal3D = document.getElementById('client3DSeatingModal');
    if (client3DEngine && modal3D && !modal3D.hidden) {
        client3DEngine.resize();
        client3DEngine.render();
    }
});
window.addEventListener('orientationchange', () => {
    const modal3D = document.getElementById('client3DSeatingModal');
    if (client3DEngine && modal3D && !modal3D.hidden) {
        setTimeout(() => {
            client3DEngine.resize();
            client3DEngine.render();
        }, 150);
    }
});

/* ==============================================================================
   MODALES DE DÉTAILS COMPLETS (ÉVÉNEMENTS, COTISATIONS, VOTES) & PARTAGE
   ============================================================================== */

// Données du partage actif & IDs modales
let currentShareData = {
    type: 'vote',
    id: 0,
    candidatId: null,
    title: '',
    url: ''
};
let currentVoteDetailId = null;
let currentEventDetailId = null;

/* --- 1. MODAL DÉTAILS ÉVÉNEMENT --- */
function openEventDetailsModal(target) {
    if (!target) return;
    const modal = document.getElementById('eventDetailsModal');
    if (!modal) return;

    let id = '';
    let name = 'Événement';
    let date = '';
    let time = '';
    let place = '';
    let category = 'Événement';
    let image = '';
    let desc = '';
    let promoter = 'Organisateur Tikéli';
    let capacity = 0;
    let stock = 0;
    let tickets = [];

    let foundData = null;
    if (typeof target === 'number' || (typeof target === 'string' && !isNaN(target))) {
        id = Number(target);
        if (window.EVENTS_DATA && Array.isArray(window.EVENTS_DATA)) {
            foundData = window.EVENTS_DATA.find(e => Number(e.id) === id);
        }
    }
    if (!id && target && target.dataset) {
        id = Number(target.dataset.eventId || target.getAttribute('data-event-id') || 0);
    }
    currentEventDetailId = id;

    if (foundData) {
        name = foundData.nom || 'Événement';
        date = foundData.date_evenement ? new Date(foundData.date_evenement).toLocaleDateString('fr-FR') : '';
        time = foundData.heure ? foundData.heure.substring(0, 5) : '';
        place = foundData.lieu || '';
        category = foundData.categorie || 'Événement';
        image = foundData.image 
            ? (foundData.image.startsWith('http') ? foundData.image : ('../uploads/events/' + foundData.image))
            : 'https://images.unsplash.com/photo-1514525253161-7a46d19cd819?auto=format&fit=crop&w=800&q=80';
        desc = foundData.description || '';
        promoter = foundData.promoteur_nom || 'Organisateur officiel';
        if (window.TICKETS_BY_EVENT && window.TICKETS_BY_EVENT[id]) {
            tickets = window.TICKETS_BY_EVENT[id];
        }
    } else {
        let btn = (typeof target === 'string' || typeof target === 'number') 
            ? document.querySelector(`button[data-event-id="${target}"]`) 
            : target;
        if (!btn && typeof target === 'object' && target.nodeType) btn = target;
        if (btn) {
            id = btn.getAttribute('data-event-id') || btn.dataset.eventId || id;
            name = btn.getAttribute('data-event-name') || btn.dataset.eventName || name;
            date = btn.getAttribute('data-event-date') || btn.dataset.eventDate || date;
            time = btn.getAttribute('data-event-time') || btn.dataset.eventTime || time;
            place = btn.getAttribute('data-event-place') || btn.dataset.eventPlace || place;
            category = btn.getAttribute('data-event-category') || btn.dataset.eventCategory || category;
            image = btn.getAttribute('data-event-image') || btn.dataset.eventImage || image;
            desc = btn.getAttribute('data-event-desc') || btn.dataset.eventDesc || desc;
            promoter = btn.getAttribute('data-event-promoter') || btn.dataset.eventPromoter || promoter;
            capacity = Number(btn.getAttribute('data-event-capacity') || btn.dataset.eventCapacity) || capacity;
            stock = Number(btn.getAttribute('data-event-stock') || btn.dataset.eventStock) || stock;
            try {
                const raw = btn.getAttribute('data-ticket-options') || btn.dataset.ticketOptions || '[]';
                tickets = typeof raw === 'string' ? JSON.parse(raw) : (Array.isArray(raw) ? raw : []);
            } catch (e) { }
        }
    }

    if (!id && target) id = target;

    const bannerImg = document.getElementById('detailEventBanner') || document.getElementById('eventDetailImg');
    if (bannerImg && image) bannerImg.src = image;

    const catEl = document.getElementById('detailEventCategory') || document.getElementById('eventDetailCategory');
    if (catEl) catEl.textContent = category;

    const titleEl = document.getElementById('detailEventTitle') || document.getElementById('eventDetailsModalTitle');
    if (titleEl) titleEl.textContent = name;

    const dateEl = document.getElementById('detailEventDate') || document.getElementById('eventDetailDateTime');
    if (dateEl) dateEl.textContent = date + (time ? (' à ' + time) : '');

    const placeEl = document.getElementById('detailEventPlace') || document.getElementById('eventDetailLieu');
    if (placeEl) placeEl.textContent = place;

    const promoterEl = document.getElementById('detailEventPromoter') || document.getElementById('eventDetailPromoteur');
    if (promoterEl) promoterEl.textContent = promoter;

    const stockEl = document.getElementById('detailEventStock') || document.getElementById('eventDetailPlaces');
    if (stockEl) stockEl.textContent = stock > 0 ? (stock + ' place(s) disponible(s)') : 'Places disponibles';

    const minPrice = (tickets && tickets.length > 0) ? Math.min(...tickets.map(t => Number(t.prix) || 0)) : 0;
    const tarifEl = document.getElementById('eventDetailTarif');
    if (tarifEl) tarifEl.textContent = minPrice > 0 ? ('À partir de ' + minPrice.toLocaleString('fr-FR') + ' F') : 'Entrée Libre';

    const descEl = document.getElementById('detailEventDesc') || document.getElementById('eventDetailDesc');
    if (descEl) descEl.textContent = desc.trim() ? desc : "Aucune description détaillée n'a été ajoutée pour cet événement.";

    // Rendu de la grille des billets
    const ticketsListEl = document.getElementById('detailEventTicketsList') || document.getElementById('eventDetailTicketsGrid');
    if (ticketsListEl) {
        ticketsListEl.innerHTML = '';
        if (tickets.length === 0) {
            ticketsListEl.innerHTML = '<div style="color: var(--muted); font-size: 0.88rem; padding: 0.5rem 0;">Aucun billet spécifique répertorié pour le moment.</div>';
        } else {
            tickets.forEach(function(tk, index) {
                const price = Number(tk.prix) || 0;
                const qte = Number(tk.quantite) || 0;
                const vendus = Number(tk.quantite_vendue) || 0;
                const rest = Math.max(0, qte - vendus);
                const soldOut = (rest <= 0);

                const div = document.createElement('div');
                div.className = 'detail-ticket-item';
                div.style.animationDelay = (index * 0.05) + 's';
                div.style.cssText = "display: flex; justify-content: space-between; align-items: center; background: #ffffff; border: 1px solid var(--line); border-radius: 8px; padding: 0.75rem 1rem;";
                div.innerHTML = `
                    <div>
                        <strong style="color: var(--navy); font-size: 0.92rem; display: block;">
                            <i class="fa-solid fa-ticket" style="color: var(--primary); margin-right: 6px;"></i> ${tk.nom || 'Billet Standard'}
                        </strong>
                        ${tk.description ? `<small style="color: var(--muted); display: block; margin-top: 2px;">${tk.description}</small>` : ''}
                        <span style="font-size: 0.78rem; font-weight: 700; color: ${soldOut ? '#ef4444' : '#16a34a'};">
                            ${soldOut ? 'Épuisé' : (rest + ' place(s) restante(s)')}
                        </span>
                    </div>
                    <div style="text-align: right;">
                        <strong style="font-size: 1.1rem; color: var(--navy);">${price.toLocaleString('fr-FR')} <span style="font-size: 0.75rem; color: var(--muted);">FCFA</span></strong>
                    </div>
                `;
                ticketsListEl.appendChild(div);
            });
        }
    }

    // Rendu de la progression des candidats si l'événement en comporte
    const candsListSection = document.getElementById('eventDetailCandidatsList');
    const candsGrid = document.getElementById('eventDetailCandidatsGrid');
    const voteLink = document.getElementById('eventDetailVoteLink');

    if (candsListSection && candsGrid) {
        candsGrid.innerHTML = '';
        const evCands = (window.CANDIDATS_DATA && window.CANDIDATS_DATA[id]) ? window.CANDIDATS_DATA[id] : [];
        if (evCands && evCands.length > 0) {
            candsListSection.style.display = 'block';
            if (voteLink) {
                voteLink.href = 'vote.php?id=' + encodeURIComponent(id) + '#candidats';
            }
            let totalVotes = 0;
            evCands.forEach(function(c) { totalVotes += Number(c.nb_votes_cand) || 0; });
            const denom = Math.max(totalVotes, 1);

            evCands.forEach(function(cand, idx) {
                const cVotes = Number(cand.nb_votes_cand) || 0;
                const cPct = Math.min(100, Math.round((cVotes / denom) * 1000) / 10);
                let photo = cand.photo || '';
                if (photo && !photo.startsWith('http')) {
                    photo = '../uploads/candidats/' + photo;
                }
                if (!photo) {
                    photo = 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=400&q=80';
                }

                const candItem = document.createElement('div');
                candItem.style.cssText = 'display: flex; flex-direction: column; gap: 4px; padding: 4px 0;';
                candItem.innerHTML = `
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                        <div style="display: flex; align-items: center; gap: 8px; min-width: 0;">
                            <img src="${photo}" alt="${cand.nom || 'Candidat'}" style="width: 28px; height: 28px; border-radius: 50%; object-fit: cover; border: 1px solid var(--line);">
                            <span style="font-size: 0.85rem; font-weight: 700; color: var(--navy); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px;">
                                ${idx === 0 ? '<span style="color: #FF4A0D; font-size: 0.75rem; margin-right: 4px; font-weight: 800;">#1</span>' : ''}${cand.nom || 'Candidat'}
                            </span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 6px; font-size: 0.78rem; font-family: 'Space Mono', monospace; font-weight: 700;">
                            <span style="color: var(--navy);">${cVotes} vote${cVotes > 1 ? 's' : ''}</span>
                            <span style="color: #FF4A0D; background: #FFF2ED; padding: 1px 6px; border-radius: 4px; font-weight: 800;">${cPct}%</span>
                        </div>
                    </div>
                    <div style="height: 6px; background: #E2E8F0; border-radius: 999px; overflow: hidden; margin-left: 36px;">
                        <div style="height: 100%; width: ${cPct}%; background: linear-gradient(90deg, #FF4A0D, #FF7A3D); border-radius: 999px; transition: width 0.4s ease;"></div>
                    </div>
                `;
                candsGrid.appendChild(candItem);
            });
        } else {
            candsListSection.style.display = 'none';
        }
    }

    // Bouton Réserver
    const bookBtn = document.getElementById('detailEventBookBtn') || document.getElementById('btnEventDetailBook');
    if (bookBtn) {
        bookBtn.onclick = function() {
            closeEventDetailsModal();
            const bookingTrigger = document.querySelector(`button[data-event-id="${id}"][data-ticket-options]`);
            if (bookingTrigger) {
                openEventModal(bookingTrigger);
            } else {
                const card = document.getElementById('event-card-' + id);
                if (card) {
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    const btnOnCard = card.querySelector('button[data-ticket-options]');
                    if (btnOnCard) openEventModal(btnOnCard);
                }
            }
        };
    }

    modal.hidden = false;
    document.body.classList.add('modal-open');
}

function closeEventDetailsModal() {
    const modal = document.getElementById('eventDetailsModal');
    if (modal) modal.hidden = true;
    document.body.classList.remove('modal-open');
}

/* --- 2. MODAL DÉTAILS COTISATION --- */
function openCotisationDetailsModal(target) {
    if (!target) return;
    const modal = document.getElementById('cotisationDetailsModal');
    if (!modal) return;

    let id = '';
    let title = 'Cotisation';
    let desc = '';
    let image = '';
    let objectif = 0;
    let collecte = 0;
    let percent = 0;
    let donateurs = 0;
    let date = 'Sans limite';
    let promoter = 'Porteur de projet';
    let status = 'En cours';

    let foundData = null;
    if (typeof target === 'number' || (typeof target === 'string' && !isNaN(target))) {
        id = Number(target);
        if (window.CAMPAGNES_DATA && Array.isArray(window.CAMPAGNES_DATA)) {
            foundData = window.CAMPAGNES_DATA.find(c => Number(c.id) === id);
        }
    }

    if (foundData) {
        title = foundData.titre || 'Campagne de cotisation';
        desc = foundData.description || '';
        image = foundData.image 
            ? (foundData.image.startsWith('http') ? foundData.image : ('../uploads/events/' + foundData.image))
            : 'https://images.unsplash.com/photo-1506905925346-21bda4d32df4?auto=format&fit=crop&w=800&q=80';
        objectif = Number(foundData.montant_objectif) || 0;
        collecte = Number(foundData.montant_collecte) || 0;
        percent = objectif > 0 ? Math.min(100, Math.round((collecte / objectif) * 100)) : 0;
        donateurs = Number(foundData.nb_contributeurs) || 0;
        promoter = foundData.promoteur_nom || 'Porteur de projet';
        status = foundData.statut === 'terminee' ? 'Terminée' : 'Active';
    } else {
        let btn = (typeof target === 'string' || typeof target === 'number') 
            ? document.querySelector(`button[data-campagne-id="${target}"]`) 
            : target;
        if (!btn && typeof target === 'object' && target.nodeType) btn = target;
        if (btn) {
            id = btn.getAttribute('data-campagne-id') || btn.dataset.campagneId || id;
            title = btn.getAttribute('data-campagne-titre') || btn.dataset.campagneTitre || title;
            desc = btn.getAttribute('data-campagne-desc') || btn.dataset.campagneDesc || desc;
            image = btn.getAttribute('data-campagne-image') || btn.dataset.campagneImage || image;
            objectif = Number(btn.getAttribute('data-campagne-objectif') || btn.dataset.campagneObjectif) || objectif;
            collecte = Number(btn.getAttribute('data-campagne-collecte') || btn.dataset.campagneCollecte) || collecte;
            percent = Number(btn.getAttribute('data-campagne-percent') || btn.dataset.campagnePercent) || percent;
            donateurs = Number(btn.getAttribute('data-campagne-donateurs') || btn.dataset.campagneDonateurs) || donateurs;
            date = btn.getAttribute('data-campagne-date') || btn.dataset.campagneDate || date;
            promoter = btn.getAttribute('data-campagne-promoter') || btn.dataset.campagnePromoter || promoter;
            status = btn.getAttribute('data-campagne-status') || btn.dataset.campagneStatus || status;
        }
    }

    if (!id && target) id = target;

    const bannerImg = document.getElementById('detailCampagneBanner') || document.getElementById('cotisationDetailImg');
    if (bannerImg && image) bannerImg.src = image;

    const statusBadge = document.getElementById('detailCampagneStatusBadge') || document.getElementById('cotisationDetailStatut');
    if (statusBadge) statusBadge.textContent = status;

    const titleEl = document.getElementById('detailCampagneTitle') || document.getElementById('cotisationDetailsModalTitle');
    if (titleEl) titleEl.textContent = title;

    const collecteVal = document.getElementById('detailCampagneCollecteVal') || document.getElementById('cotisationDetailCollecte');
    if (collectaVal) collecteVal.textContent = collecte.toLocaleString('fr-FR') + ' FCFA';

    const percentVal = document.getElementById('detailCampagnePercentVal') || document.getElementById('cotisationDetailPct');
    if (percentVal) percentVal.textContent = percent + '%';

    const progressBar = document.getElementById('detailCampagneProgressBar') || document.getElementById('cotisationDetailBar');
    if (progressBar) {
        progressBar.style.width = '0%';
        setTimeout(function() {
            progressBar.style.width = Math.min(100, percent) + '%';
        }, 80);
    }

    const objectifVal = document.getElementById('detailCampagneObjectifVal') || document.getElementById('cotisationDetailObjectif');
    if (objectifVal) objectifVal.textContent = objectif.toLocaleString('fr-FR') + ' FCFA';

    const donateursVal = document.getElementById('detailCampagneDonateursVal') || document.getElementById('cotisationDetailContributeurs');
    if (donateursVal) donateursVal.textContent = donateurs;

    const promoterEl = document.getElementById('detailCampagnePromoter') || document.getElementById('cotisationDetailPromoteur');
    if (promoterEl) promoterEl.textContent = promoter;

    const descEl = document.getElementById('detailCampagneDesc') || document.getElementById('cotisationDetailDesc');
    if (descEl) descEl.textContent = desc.trim() ? desc : "Aucune description détaillée fournie pour cette campagne.";

    // Bouton Contribuer
    const actionBtn = document.getElementById('detailCampagneActionBtn') || document.getElementById('btnCotisationDetailAction');
    if (actionBtn) {
        actionBtn.onclick = function() {
            closeCotisationDetailsModal();
            const btnInCard = document.querySelector(`button[data-campagne-id="${id}"]`);
            if (btnInCard) {
                openCotisationModal(btnInCard);
            }
        };
    }

    modal.hidden = false;
    document.body.classList.add('modal-open');
}

function closeCotisationDetailsModal() {
    const modal = document.getElementById('cotisationDetailsModal');
    if (modal) modal.hidden = true;
    document.body.classList.remove('modal-open');
}

/* --- 3. MODAL DÉTAILS VOTE & CARROUSEL HORIZONTAL DES CANDIDATS --- */
function resolveCandPhoto(photo) {
    if (!photo) return 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=400&q=80';
    if (photo.startsWith('http')) return photo;
    return '../uploads/candidats/' + photo;
}

function resolveEventPhoto(photo) {
    if (!photo) return 'https://images.unsplash.com/photo-1516450360452-9312f5e86fc7?auto=format&fit=crop&w=800&q=80';
    if (photo.startsWith('http')) return photo;
    return '../uploads/events/' + photo;
}

function scrollVoteCands(direction) {
    const track = document.getElementById('voteModalCandsTrack');
    if (track) {
        track.scrollBy({ left: direction * 230, behavior: 'smooth' });
    }
}

function openVoteDetailsModal(target) {
    if (!target) return;
    const modal = document.getElementById('voteDetailsModal');
    if (!modal) return;

    let id = null;
    if (typeof target === 'number' || (typeof target === 'string' && !isNaN(target))) {
        id = Number(target);
    } else if (target && target.dataset) {
        id = Number(target.dataset.eventId || target.dataset.voteId || target.getAttribute('data-event-id'));
    }
    currentVoteDetailId = id;

    let foundData = null;
    if (id && window.VOTES_DATA && Array.isArray(window.VOTES_DATA)) {
        foundData = window.VOTES_DATA.find(v => Number(v.id) === id);
    }

    let title = foundData ? foundData.nom : 'Concours de vote';
    let category = foundData ? foundData.categorie : 'Vote';
    let date = foundData && foundData.date_evenement ? new Date(foundData.date_evenement).toLocaleDateString('fr-FR') : '';
    let time = foundData && foundData.heure ? foundData.heure.substring(0, 5) : '';
    let place = foundData ? foundData.lieu : 'En ligne';
    let image = resolveEventPhoto(foundData ? foundData.image : '');
    let desc = foundData ? (foundData.description || '') : '';
    let question = foundData ? (foundData.vote_question || '') : '';
    let prix = foundData ? (Number(foundData.prix_vote) || 0) : 0;
    let totalVotes = foundData ? (Number(foundData.nb_votes) || 0) : 0;
    let promoter = foundData ? (foundData.promoteur_nom || 'Organisateur officiel') : 'Organisateur officiel';
    let candidats = (id && window.CANDIDATS_DATA && window.CANDIDATS_DATA[id]) ? window.CANDIDATS_DATA[id] : [];

    // Remplissage des champs de la modale
    const bannerImg = document.getElementById('voteDetailImg');
    if (bannerImg) bannerImg.src = image;

    const catBadge = document.getElementById('voteDetailCategory');
    if (catBadge) catBadge.textContent = category;

    const tarifBadge = document.getElementById('voteDetailTarifBadge');
    if (tarifBadge) {
        tarifBadge.innerHTML = prix > 0 
            ? `<i class="fa-solid fa-coins" style="color: #FF4A0D; margin-right: 4px;"></i> ${prix.toLocaleString('fr-FR')} F / vote`
            : `<i class="fa-solid fa-gift" style="color: #10B981; margin-right: 4px;"></i> Vote Gratuit`;
    }

    const titleEl = document.getElementById('voteDetailsModalTitle');
    if (titleEl) titleEl.textContent = title;

    const dateEl = document.getElementById('voteDetailDateTime');
    if (dateEl) dateEl.textContent = date + (time ? (' à ' + time) : '');

    const placeEl = document.getElementById('voteDetailLieu');
    if (placeEl) placeEl.textContent = place;

    const tarifEl = document.getElementById('voteDetailTarif');
    if (tarifEl) tarifEl.textContent = prix > 0 ? (prix.toLocaleString('fr-FR') + ' FCFA') : 'Gratuit';

    const totalVotesEl = document.getElementById('voteDetailTotalVotes');
    if (totalVotesEl) totalVotesEl.textContent = totalVotes.toLocaleString('fr-FR') + ' vote(s)';

    const promoterEl = document.getElementById('voteDetailPromoteur');
    if (promoterEl) promoterEl.textContent = promoter;

    const qBox = document.getElementById('voteDetailQuestionBox');
    const questionEl = document.getElementById('voteDetailQuestionText');
    if (questionEl) {
        questionEl.textContent = question ? `« ${question} »` : '';
        if (qBox) qBox.style.display = question ? 'block' : 'none';
    }

    const descEl = document.getElementById('voteDetailDesc');
    if (descEl) descEl.textContent = desc.trim() ? desc : "Aucune description détaillée n'a été fournie pour ce scrutin.";

    const fullPageBtn = document.getElementById('btnVoteDetailFullPage');
    if (fullPageBtn && id) {
        fullPageBtn.href = 'vote.php?id=' + encodeURIComponent(id);
    }

    // Rendu du Carrousel Horizontal des Candidats
    const track = document.getElementById('voteModalCandsTrack');
    const countBadge = document.getElementById('voteModalCandsCount');
    if (countBadge) countBadge.textContent = candidats.length;

    if (track) {
        track.innerHTML = '';
        if (candidats.length === 0) {
            track.innerHTML = `
                <div style="color: var(--muted); font-size: 0.88rem; padding: 1.5rem 1rem; width: 100%; text-align: center; background: #F8FAFC; border: 1px dashed var(--line); border-radius: 8px;">
                    <i class="fa-solid fa-user-group" style="font-size: 1.5rem; color: #CBD5E1; margin-bottom: 6px; display: block;"></i>
                    Aucun candidat individuel répertorié. Le scrutin est global pour cet événement.
                </div>
            `;
        } else {
            const isPayant = (prix > 0);
            candidats.forEach(function(cand, idx) {
                const cId = Number(cand.id);
                const cVotes = Number(cand.nb_votes_cand || 0);
                const cPct = (totalVotes > 0) ? Math.min(100, Math.round((cVotes / totalVotes) * 1000) / 10) : 0;
                const cPhoto = resolveCandPhoto(cand.photo);
                const rankNum = idx + 1;
                const isTop1 = (rankNum === 1);
                const safeNom = (cand.nom || 'Candidat').replace(/"/g, '&quot;');

                const card = document.createElement('article');
                card.className = 'vote-cand-card';
                card.id = 'cand-card-' + cId;

                card.innerHTML = `
                    <div class="vote-cand-photo-wrap" onclick="openCandidatDetailsModal(${cId}, ${id})" title="Voir les détails de ${safeNom}">
                        <img src="${cPhoto}" alt="${safeNom}" class="vote-cand-photo" loading="lazy">
                        <span class="vote-cand-badge ${isTop1 ? 'top-1' : ''}">${isTop1 ? '★ #1' : '#' + rankNum}</span>
                    </div>
                    <div class="vote-cand-body">
                        <h4 class="vote-cand-nom" title="${safeNom}">${cand.nom}</h4>
                        <div class="vote-cand-stats">
                            <span id="cand-votes-${cId}" style="color: var(--navy);">${cVotes} vote${cVotes > 1 ? 's' : ''}</span>
                            <span id="cand-pct-${cId}" style="color: #FF4A0D; background: #FFF2ED; padding: 1px 5px; border-radius: 4px;">${cPct}%</span>
                        </div>
                        <div class="vote-cand-gauge-bg">
                            <div class="vote-cand-gauge-fill" id="cand-gauge-${cId}" style="width: ${cPct}%;"></div>
                        </div>
                        <div class="vote-cand-actions">
                            <button type="button" class="btn-cand-detail" onclick="openCandidatDetailsModal(${cId}, ${id})" title="Voir le profil">
                                <i class="fa-solid fa-eye"></i> Détails
                            </button>
                            <button type="button" class="btn-cand-vote" id="btn-vote-cand-${cId}" 
                                onclick="voteForCandidate(${id}, ${cId}, '${safeNom.replace(/'/g, "\\'")}', ${isPayant}, ${prix})" 
                                title="Voter pour ${safeNom}">
                                <i class="fa-solid ${isPayant ? 'fa-coins' : 'fa-thumbs-up'}"></i> Voter
                            </button>
                        </div>
                    </div>
                `;
                track.appendChild(card);
            });
        }
    }

    modal.hidden = false;
    document.body.classList.add('modal-open');
}

function closeVoteDetailsModal() {
    const modal = document.getElementById('voteDetailsModal');
    if (modal) modal.hidden = true;
    document.body.classList.remove('modal-open');
}

/* --- 3.1. MODALE DÉTAILS D'UN CANDIDAT INDIVIDUEL --- */
function openCandidatDetailsModal(candId, eventId) {
    const modal = document.getElementById('candidatDetailsModal');
    if (!modal) return;

    let cId = Number(candId);
    let eId = Number(eventId);

    const candsList = (window.CANDIDATS_DATA && window.CANDIDATS_DATA[eId]) ? window.CANDIDATS_DATA[eId] : [];
    const cand = candsList.find(c => Number(c.id) === cId);
    if (!cand) return;

    const eventObj = (window.VOTES_DATA && Array.isArray(window.VOTES_DATA)) 
        ? window.VOTES_DATA.find(v => Number(v.id) === eId) 
        : null;

    const rankIdx = candsList.findIndex(c => Number(c.id) === cId);
    const rankNum = (rankIdx !== -1) ? (rankIdx + 1) : 1;
    const isTop1 = (rankNum === 1);

    let totalEventVotes = eventObj ? (Number(eventObj.nb_votes) || 0) : 0;
    let cVotes = Number(cand.nb_votes_cand || 0);
    let cPct = (totalEventVotes > 0) ? Math.min(100, Math.round((cVotes / totalEventVotes) * 1000) / 10) : 0;
    let isPayant = eventObj ? ((Number(eventObj.prix_vote) || 0) > 0) : false;
    let prix = eventObj ? (Number(eventObj.prix_vote) || 0) : 0;

    // Remplissage de la modale de détails
    const photoEl = document.getElementById('candModalPhoto');
    if (photoEl) photoEl.src = resolveCandPhoto(cand.photo);

    const rankBadge = document.getElementById('candModalRankBadge');
    if (rankBadge) {
        rankBadge.className = 'vote-cand-badge ' + (isTop1 ? 'top-1' : '');
        rankBadge.textContent = isTop1 ? '★ #1 en tête' : ('#' + rankNum + ' en lice');
    }

    const nomEl = document.getElementById('candModalNom');
    if (nomEl) nomEl.textContent = cand.nom;

    const eventNameEl = document.getElementById('candModalEventName');
    if (eventNameEl) {
        eventNameEl.querySelector('span').textContent = eventObj ? eventObj.nom : 'Concours officiel';
    }

    const votesCountEl = document.getElementById('candModalVotesCount');
    if (votesCountEl) votesCountEl.textContent = cVotes.toLocaleString('fr-FR');

    const pctCountEl = document.getElementById('candModalPctCount');
    if (pctCountEl) pctCountEl.textContent = cPct + '%';

    const gaugePctEl = document.getElementById('candModalGaugePct');
    if (gaugePctEl) gaugePctEl.textContent = cPct + '%';

    const gaugeFillEl = document.getElementById('candModalGaugeFill');
    if (gaugeFillEl) gaugeFillEl.style.width = cPct + '%';

    const bioEl = document.getElementById('candModalBio');
    if (bioEl) {
        bioEl.textContent = cand.description && cand.description.trim() 
            ? cand.description 
            : "Ce(tte) candidat(e) est en compétition officielle. Soutenez sa candidature en lui accordant vos votes !";
    }

    // Bouton Voter pour elle
    const voteBtn = document.getElementById('candModalVoteBtn');
    const voteBtnText = document.getElementById('candModalVoteBtnText');
    if (voteBtn && voteBtnText) {
        voteBtnText.textContent = isPayant ? `Voter pour elle (${prix.toLocaleString('fr-FR')} F)` : 'Voter pour elle';
        voteBtn.onclick = function() {
            voteForCandidate(eId, cId, cand.nom, isPayant, prix);
        };
    }

    // Bouton Partager
    const shareBtn = document.getElementById('candModalShareBtn');
    if (shareBtn) {
        shareBtn.onclick = function() {
            const evNom = eventObj ? eventObj.nom : 'Concours';
            openShareVoteCandidate(eId, evNom, cId, cand.nom);
        };
    }

    modal.hidden = false;
    document.body.classList.add('modal-open');
}

function closeCandidatDetailsModal() {
    const modal = document.getElementById('candidatDetailsModal');
    if (modal) modal.hidden = true;
}

/* --- 3.2. ACTION DE VOTE DIRECT POUR UN CANDIDAT --- */
async function voteForCandidate(eventId, candId, candNom, isPayant, prix) {
    if (isPayant) {
        // Vote payant : initialisation du paiement
        const formData = new FormData();
        formData.append('event_id', eventId);
        formData.append('candidat_ids', candId);
        formData.append('phase', '2');
        try {
            const res = await fetch('vote-event.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.redirect) {
                window.location.href = data.redirect;
            } else if (data.error) {
                alert(data.error);
            }
        } catch (err) {
            alert("Erreur lors de l'initialisation du paiement sécurisé.");
        }
        return;
    }

    // Vote gratuit : toggle immédiat
    const formData = new FormData();
    formData.append('event_id', eventId);
    formData.append('candidat_id', candId);

    try {
        const res = await fetch('vote-event.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.error) {
            alert(data.error);
            return;
        }

        // Mise à jour de la mémoire cache locale
        if (window.CANDIDATS_DATA && window.CANDIDATS_DATA[eventId]) {
            const candObj = window.CANDIDATS_DATA[eventId].find(c => Number(c.id) === Number(candId));
            if (candObj && typeof data.cand_votes !== 'undefined') {
                candObj.nb_votes_cand = data.cand_votes;
            }
        }

        // Mise à jour de la carte candidate
        const candVotesEl = document.getElementById('cand-votes-' + candId);
        if (candVotesEl && typeof data.cand_votes !== 'undefined') {
            candVotesEl.textContent = data.cand_votes + (data.cand_votes > 1 ? ' votes' : ' vote');
        }
        const voteBtn = document.getElementById('btn-vote-cand-' + candId);
        if (voteBtn) {
            voteBtn.classList.toggle('voted', data.voted);
            voteBtn.innerHTML = data.voted 
                ? '<i class="fa-solid fa-check"></i> Voté' 
                : '<i class="fa-solid fa-thumbs-up"></i> Voter';
        }

        // Mise à jour de la modale détail si ouverte
        const modalVotesEl = document.getElementById('candModalVotesCount');
        if (modalVotesEl && typeof data.cand_votes !== 'undefined') {
            modalVotesEl.textContent = Number(data.cand_votes).toLocaleString('fr-FR');
        }
        const modalBtnTxt = document.getElementById('candModalVoteBtnText');
        if (modalBtnTxt) {
            modalBtnTxt.textContent = data.voted ? 'Voté pour elle' : 'Voter pour elle';
        }

        // Mise à jour globale de l'événement
        if (typeof data.votes !== 'undefined') {
            if (window.VOTES_DATA && Array.isArray(window.VOTES_DATA)) {
                const evObj = window.VOTES_DATA.find(v => Number(v.id) === Number(eventId));
                if (evObj) evObj.nb_votes = data.votes;
            }
            const totalVotesEl = document.getElementById('voteDetailTotalVotes');
            if (totalVotesEl) totalVotesEl.textContent = Number(data.votes).toLocaleString('fr-FR') + ' vote(s)';
            
            // Recalcul des barres de progression
            recalculateCandidatGauges(eventId, data.votes);

            // Mise à jour de la carte événement principale en fond
            const cardMain = document.getElementById('vote-card-' + eventId);
            if (cardMain) {
                const counter = cardMain.querySelector('.vote-counter');
                if (counter) {
                    counter.innerHTML = `<i class="fa-solid fa-star" style="color:#FF4A0D;"></i> ${data.votes} vote${data.votes > 1 ? 's' : ''}`;
                }
            }
        }

        alert(data.voted ? `Votre vote a été validé avec succès pour ${candNom} !` : `Votre vote pour ${candNom} a été retiré.`);

    } catch (e) {
        alert("Erreur réseau lors de l'enregistrement du vote.");
    }
}

function recalculateCandidatGauges(eventId, totalVotes) {
    const cands = (window.CANDIDATS_DATA && window.CANDIDATS_DATA[eventId]) ? window.CANDIDATS_DATA[eventId] : [];
    const denom = Math.max(1, totalVotes);
    cands.forEach(c => {
        const v = Number(c.nb_votes_cand || 0);
        const pct = Math.min(100, Math.round((v / denom) * 1000) / 10);
        const pctEl = document.getElementById('cand-pct-' + c.id);
        const gaugeEl = document.getElementById('cand-gauge-' + c.id);
        if (pctEl) pctEl.textContent = pct + '%';
        if (gaugeEl) gaugeEl.style.width = pct + '%';
    });
}

function openVoteDetails(target) {
    openVoteDetailsModal(target);
}

function openCotisationDetails(target) {
    openCotisationDetailsModal(target);
}

function openEventDetails(target) {
    openEventDetailsModal(target);
}

function openShareVote(voteId, title, category) {
    if (!voteId) return;
    openShareModal({
        type: 'vote',
        id: Number(voteId),
        title: title || 'Concours de vote',
        subtitle: category ? ('Catégorie : ' + category) : 'Vote officiel en ligne • Partagez pour maximiser les suffrages'
    });
}

function openShareEvent(eventId, title, place, date) {
    if (!eventId) return;
    openShareModal({
        type: 'evenement',
        id: Number(eventId),
        title: title || 'Événement',
        subtitle: (place ? place : '') + (date ? (' • ' + date) : '')
    });
}

function openShareCotisation(campagneId, title) {
    if (!campagneId) return;
    openShareModal({
        type: 'cotisation',
        id: Number(campagneId),
        title: title || 'Campagne de cotisation',
        subtitle: 'Cotisation solidaire officielle • Partagez pour mobiliser les contributions'
    });
}

function openShareVoteFromModal() {
    const title = document.getElementById('voteDetailsModalTitle')?.textContent || 'Concours de vote';
    const cat = document.getElementById('voteDetailCategory')?.textContent || '';
    if (currentVoteDetailId) {
        openShareVote(currentVoteDetailId, title, cat);
    }
}

function openShareEventFromModal() {
    const title = document.getElementById('eventDetailsModalTitle')?.textContent || 'Événement';
    const place = document.getElementById('eventDetailLieu')?.textContent || '';
    const date = document.getElementById('eventDetailDateTime')?.textContent || '';
    if (currentEventDetailId) {
        openShareEvent(currentEventDetailId, title, place, date);
    }
}

function openShareVoteModal(event, voteId, title, category) {
    if (event && event.stopPropagation) event.stopPropagation();
    openShareVote(voteId, title, category);
}

function openShareVoteCandidate(voteId, voteTitle, candId, candNom) {
    openShareModal({
        type: 'vote',
        id: voteId,
        candidatId: candId,
        candNom: candNom,
        title: "Votez pour " + candNom + " • " + voteTitle,
        subtitle: "Candidat(e) en compétition officielle"
    });
}

function openShareModal(options) {
    if (!options || !options.id) return;

    const modal = document.getElementById('shareModal');
    if (!modal) return;

    const type = options.type || 'vote';
    const id = options.id;
    const title = options.title || 'Découvrez cette publication sur Tikéli';

    // Construction du lien permanent avec paramètres de deep-link
    const loc = window.location;
    const baseUrl = loc.protocol + '//' + loc.host + loc.pathname;
    let permalink = '';

    if (type === 'vote') {
        const voteUrl = baseUrl.replace(/\/[^\/]*$/, '/vote.php');
        permalink = voteUrl + '?id=' + encodeURIComponent(id);
        if (options.candidatId) {
            permalink += '&candidat_id=' + encodeURIComponent(options.candidatId) + '#candidat-' + encodeURIComponent(options.candidatId);
        }
    } else if (type === 'cotisation') {
        const cotUrl = baseUrl.replace(/\/[^\/]*$/, '/cotisation.php');
        permalink = cotUrl + '?id=' + encodeURIComponent(id);
    } else {
        const evUrl = baseUrl.replace(/\/[^\/]*$/, '/evenement.php');
        permalink = evUrl + '?id=' + encodeURIComponent(id);
    }

    currentShareData = {
        type: type,
        id: id,
        candidatId: options.candidatId || null,
        candNom: options.candNom || null,
        title: title,
        url: permalink
    };

    const targetTitle = document.getElementById('shareTargetTitle') || document.getElementById('shareModalTitle');
    if (targetTitle) targetTitle.textContent = title;

    const targetSub = document.getElementById('shareTargetSubtitle') || document.getElementById('shareModalSubtitle');
    if (targetSub) {
        if (options.subtitle) {
            targetSub.textContent = options.subtitle;
        } else {
            targetSub.textContent = (type === 'vote') 
                ? 'Vote officiel en ligne • Partagez pour maximiser les suffrages'
                : (type === 'cotisation' ? 'Campagne de cotisation solidaire' : 'Billetterie événementielle officielle');
        }
    }

    const input = document.getElementById('sharePermalinkInput') || document.getElementById('shareLinkInput');
    if (input) input.value = permalink;

    const copyBtn = document.getElementById('shareCopyBtn') || document.getElementById('btnCopyShareLink');
    if (copyBtn) copyBtn.innerHTML = '<i class="fa-regular fa-copy"></i> Copier';

    // Mettre à jour les liens directs WhatsApp, FB, TW, SMS
    const waBtn = document.getElementById('shareWaBtn');
    if (waBtn) {
        let msg = '';
        if (type === 'vote' && options.candNom) {
            msg = "🗳️ Votez pour " + options.candNom + " dans « " + title + " » sur Tikéli ! Cliquez ici : " + permalink;
        } else if (type === 'vote') {
            msg = "🗳️ Participez au vote « " + title + " » sur Tikéli ! Cliquez ici : " + permalink;
        } else {
            msg = "🎟️ Découvrez « " + title + " » sur Tikéli ! Cliquez ici : " + permalink;
        }
        waBtn.href = "https://api.whatsapp.com/send?text=" + encodeURIComponent(msg);
    }

    const fbBtn = document.getElementById('shareFbBtn');
    if (fbBtn) fbBtn.href = "https://www.facebook.com/sharer/sharer.php?u=" + encodeURIComponent(permalink);

    const twBtn = document.getElementById('shareTwBtn');
    if (twBtn) twBtn.href = "https://twitter.com/intent/tweet?url=" + encodeURIComponent(permalink) + "&text=" + encodeURIComponent((options.candNom ? ("Votez pour " + options.candNom + " - ") : "") + title);

    const smsBtn = document.getElementById('shareSmsBtn');
    if (smsBtn) smsBtn.href = "sms:?body=" + encodeURIComponent("Votez / Participez : " + title + " " + permalink);

    const nativeContainer = document.getElementById('nativeShareContainer') || document.getElementById('nativeShareWrapper');
    if (nativeContainer) {
        nativeContainer.style.display = (navigator && navigator.share) ? 'block' : 'none';
    }

    modal.hidden = false;
    document.body.classList.add('modal-open');
}

function closeShareModal() {
    const modal = document.getElementById('shareModal');
    if (modal) modal.hidden = true;
    document.body.classList.remove('modal-open');
}

function copyShareLink() {
    const input = document.getElementById('sharePermalinkInput') || document.getElementById('shareLinkInput');
    if (!input) return;

    const url = input.value;
    const copySuccess = document.getElementById('shareCopySuccessMsg');
    const copyBtn = document.getElementById('shareCopyBtn') || document.getElementById('btnCopyShareLink');

    function onCopied() {
        if (copySuccess) {
            copySuccess.style.display = 'flex';
            setTimeout(() => { copySuccess.style.display = 'none'; }, 4000);
        }
        if (copyBtn) {
            copyBtn.classList.add('copied');
            copyBtn.innerHTML = '<i class="fa-solid fa-check"></i> Copié !';
            setTimeout(() => {
                copyBtn.classList.remove('copied');
                copyBtn.innerHTML = '<i class="fa-regular fa-copy"></i> Copier';
            }, 2500);
        }
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(onCopied).catch(function() {
            input.select();
            document.execCommand('copy');
            onCopied();
        });
    } else {
        input.select();
        document.execCommand('copy');
        onCopied();
    }
}

function shareViaWhatsApp() {
    let message = '';
    if (currentShareData.type === 'vote' && currentShareData.candNom) {
        message = "🗳️ Soutenez et votez pour " + currentShareData.candNom + " sur Tikéli :\n" + currentShareData.url;
    } else if (currentShareData.type === 'vote') {
        message = "🗳️ Participez au vote : " + currentShareData.title + " sur Tikéli :\n" + currentShareData.url;
    } else {
        message = "🎟️ Découvrez " + currentShareData.title + " sur Tikéli :\n" + currentShareData.url;
    }
    window.open('https://api.whatsapp.com/send?text=' + encodeURIComponent(message), '_blank');
}

function shareViaFacebook() {
    window.open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(currentShareData.url), '_blank', 'width=600,height=450');
}

function shareViaTwitter() {
    const tweet = (currentShareData.candNom ? ("Votez pour " + currentShareData.candNom) : ("Participez au vote : " + currentShareData.title)) + " sur @TikeliCi\n";
    window.open('https://twitter.com/intent/tweet?text=' + encodeURIComponent(tweet) + '&url=' + encodeURIComponent(currentShareData.url), '_blank', 'width=600,height=450');
}

function shareViaSMS() {
    const text = "Votez / Participez : " + currentShareData.title + " " + currentShareData.url;
    window.open('sms:?body=' + encodeURIComponent(text), '_self');
}

function triggerNativeShare() {
    if (navigator && navigator.share) {
        navigator.share({
            title: currentShareData.title,
            text: (currentShareData.type === 'vote' ? "Participez au vote : " : "Découvrez ") + currentShareData.title,
            url: currentShareData.url
        }).catch(e => console.log("Partage annulé ou non disponible:", e));
    }
}

/* --- GESTION DU DEEP LINKING AU CHARGEMENT DE LA PAGE --- */
document.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    if (params.has('vote_id')) {
        const voteId = params.get('vote_id');
        const candId = params.get('candidat_id');
        const card = document.getElementById('vote-card-' + voteId);
        if (card) {
            setTimeout(() => {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                const detailBtn = card.querySelector('button[data-vote-id]');
                if (detailBtn) openVoteDetailsModal(detailBtn);

                // Si un candidat spécifique est ciblé dans le lien, le mettre en surbrillance
                if (candId) {
                    setTimeout(() => {
                        const candEl = document.getElementById('detail-cand-' + candId);
                        if (candEl) {
                            candEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            candEl.style.boxShadow = '0 0 0 2px #FF4A0D';
                            candEl.style.background = '#FFF2ED';
                        }
                    }, 400);
                }
            }, 300);
        }
    } else if (params.has('campagne_id')) {
        const campId = params.get('campagne_id');
        const card = document.getElementById('campagne-card-' + campId);
        if (card) {
            setTimeout(() => {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                const detailBtn = card.querySelector('button[data-campagne-id]');
                if (detailBtn) openCotisationDetailsModal(detailBtn);
            }, 300);
        }
    } else if (params.has('event_id')) {
        const evId = params.get('event_id');
        const card = document.getElementById('event-card-' + evId);
        if (card) {
            setTimeout(() => {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                const detailBtn = card.querySelector('button[data-event-id]');
                if (detailBtn) openEventDetailsModal(detailBtn);
            }, 300);
        }
    }
});

// Fermeture au clic sur l'arrière-plan (backdrop)
document.addEventListener('click', function (e) {
    if (e.target && e.target.classList && e.target.classList.contains('client-modal')) {
        closeEventDetailsModal();
        closeCotisationDetailsModal();
        closeVoteDetailsModal();
        closeShareModal();
        if (typeof closeEventModal === 'function') closeEventModal();
        if (typeof closeVoteModal === 'function') closeVoteModal();
        if (typeof closeCotisationModal === 'function') closeCotisationModal();
    }
});

// Écouteur global Escape pour fermer toutes les modales
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeEventDetailsModal();
        closeCotisationDetailsModal();
        closeVoteDetailsModal();
        closeShareModal();
        if (typeof closeEventModal === 'function') closeEventModal();
        if (typeof closeVoteModal === 'function') closeVoteModal();
        if (typeof closeCotisationModal === 'function') closeCotisationModal();
    }
});

// Exposer explicitement toutes les fonctions d'interaction au scope global window
window.openEventModal = openEventModal;
window.closeEventModal = closeEventModal;
window.changeQty = changeQty;
window.updateMultiTicketTotal = updateMultiTicketTotal;
window.toggleSeatMap = toggleSeatMap;
window.toggleDirectNumberedSeats = toggleDirectNumberedSeats;
window.toggleSingleDirectSeat = toggleSingleDirectSeat;
window.openClient3DSeating = openClient3DSeating;
window.closeClient3DSeating = closeClient3DSeating;
window.applyClient3DSelection = applyClient3DSelection;
window.switchClient3DTab = switchClient3DTab;
window.filterClient3D = filterClient3D;
window.setClient3DView = setClient3DView;
window.removeClient3DSeat = removeClient3DSeat;
window.openVoteModal = openVoteModal;
window.closeVoteModal = closeVoteModal;
window.openCotisationModal = openCotisationModal;
window.closeCotisationModal = closeCotisationModal;
window.setCotisation = setCotisation;
window.toggleLike = toggleLike;
window.toggleDesc = toggleDesc;
window.toggleMobileSeatList = toggleMobileSeatList;

// Nouvelles fonctions de détails et partage
window.openEventDetails = openEventDetails;
window.openEventDetailsModal = openEventDetailsModal;
window.closeEventDetailsModal = closeEventDetailsModal;
window.openCotisationDetails = openCotisationDetails;
window.openCotisationDetailsModal = openCotisationDetailsModal;
window.closeCotisationDetailsModal = closeCotisationDetailsModal;
window.openVoteDetails = openVoteDetails;
window.openVoteDetailsModal = openVoteDetailsModal;
window.closeVoteDetailsModal = closeVoteDetailsModal;
window.scrollVoteCands = scrollVoteCands;
window.openCandidatDetailsModal = openCandidatDetailsModal;
window.closeCandidatDetailsModal = closeCandidatDetailsModal;
window.voteForCandidate = voteForCandidate;
window.openShareVote = openShareVote;
window.openShareEvent = openShareEvent;
window.openShareCotisation = openShareCotisation;
window.openShareVoteFromModal = openShareVoteFromModal;
window.openShareEventFromModal = openShareEventFromModal;
window.openShareVoteModal = openShareVoteModal;
window.openShareVoteCandidate = openShareVoteCandidate;
window.openShareModal = openShareModal;
window.closeShareModal = closeShareModal;
window.copyShareLink = copyShareLink;
window.shareViaWhatsApp = shareViaWhatsApp;
window.shareViaFacebook = shareViaFacebook;
window.shareViaTwitter = shareViaTwitter;
window.shareViaSMS = shareViaSMS;
window.triggerNativeShare = triggerNativeShare;

