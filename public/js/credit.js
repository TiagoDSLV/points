/**
 * -------------------------------------------------------------------------
 * Credit plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Credit.
 *
 * Credit is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * Credit is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Credit. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @author    François Legastelois
 * @copyright Copyright (C) 2017-2023 by Credit plugin team.
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/pluginsGLPI/credit
 * -------------------------------------------------------------------------
 */

(function () {
    'use strict';

    /**
     * Inject a "X pts" badge into every TicketTask timeline card that has
     * consumed points recorded. Cards are identified by id="TicketTask_{id}".
     * Runs once on DOM ready, then re-runs whenever the timeline container
     * gains new child nodes (GLPI sometimes refreshes the timeline via AJAX).
     */
    function injectPointsBadges(root) {
        root = root || document;
        root.querySelectorAll('[id^="TicketTask_"]:not([data-credit-badge-done])').forEach(function (card) {
            var taskId = parseInt(card.id.replace('TicketTask_', ''), 10);
            if (!taskId) return;

            card.setAttribute('data-credit-badge-done', '1');

            $.ajax({
                type: 'POST',
                url: CFG_GLPI.root_doc + '/plugins/credit/ajax/getTaskPoints.php',
                data: { tickettask_id: taskId },
                dataType: 'json',
            }).done(function (data) {
                if (!data || !data.points) return;

                var badge = document.createElement('span');
                // Use same classes as the "Créé :" / "Dernière mise à jour :" badges
                badge.className = 'badge user-select-auto text-wrap ms-1 d-none d-md-flex align-items-center flex-wrap credit-pts-meta';
                badge.innerHTML = '<i class="ti ti-coin me-1"></i>Pts : <strong class="ms-1">' + data.points + '</strong>';

                // GLPI 11 task cards: metadata lives in .d-flex.creator inside .timeline-header
                var creator = card.querySelector('.d-flex.creator');
                if (creator) {
                    creator.appendChild(badge);
                } else {
                    // Fallback for unexpected DOM structure
                    var target = card.querySelector('.timeline-header')
                              || card.querySelector('.card-body')
                              || card;
                    target.appendChild(badge);
                }
            });
        });
    }

    var _creditBadgeTimeout = null;
    function _debouncedInject() {
        if (_creditBadgeTimeout) clearTimeout(_creditBadgeTimeout);
        _creditBadgeTimeout = setTimeout(injectPointsBadges, 150);
    }

    function setupObserver() {
        // Observe the whole body so we catch the timeline even when it loads
        // asynchronously after DOMContentLoaded (common in GLPI 11).
        new MutationObserver(_debouncedInject)
            .observe(document.body, { childList: true, subtree: true });

        // Run immediately and once more after a short delay for already-rendered cards.
        injectPointsBadges();
        setTimeout(injectPointsBadges, 800);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupObserver);
    } else {
        setupObserver();
    }
})();
