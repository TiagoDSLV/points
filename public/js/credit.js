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
                badge.className = 'badge bg-purple credit-points-badge ms-1';
                badge.title = 'Points consommés';
                badge.innerHTML = '<i class="ti ti-coin me-1"></i>' + data.points + ' pts';

                // Try to inject near the duration badge (usually in .card-header or .b-card-header)
                var target = card.querySelector('.card-header .badge')
                          || card.querySelector('.b-card-header .badge')
                          || card.querySelector('.card-header')
                          || card.querySelector('.b-card-header')
                          || card;
                target.insertAdjacentElement('afterend', badge);
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
