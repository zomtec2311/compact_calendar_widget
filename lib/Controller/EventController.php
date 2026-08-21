<?php
/**
 *
 * Compact Calendar Widget APP (Nextcloud)
 *
 * @author Wolfgang Tödt <wtoedt@gmail.com>
 *
 * @copyright Copyright (c) 2026 Wolfgang Tödt
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

namespace OCA\CompactCalendarWidget\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IAppConfig;
use OCP\Calendar\IManager;
use OCP\Calendar\ICalendar;
use Psr\Log\LoggerInterface;

class EventController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private IManager $calendarManager,
        private readonly LoggerInterface $logger,
        private IAppConfig $appConfig
    ) {
        parent::__construct($appName, $request);
    }

    public function getCalendars(): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse([], 401);
        }

        $userCalendars = $this->calendarManager->getCalendars();

        $calendars = [];
        foreach ($userCalendars as $calendar) {
            if (!$calendar instanceof ICalendar) {
                continue;
            }

            $uri = $calendar->getUri();

            if (str_contains($uri, 'trash') || str_contains($uri, 'delete')) {
                continue;
            }

            $calendars[] = [
                'id' => $calendar->getKey(),
                'displayname' => $calendar->getDisplayName(),
                'uri' => $uri,
                'color' => method_exists($calendar, 'getColor') ? $calendar->getColor() : null,
            ];
        }

        $rawSelected = $this->appConfig->getValueString($this->appName, $user->getUID() . '_selected_calendars', '[]');
        $selected = json_decode($rawSelected, true) ?: [];

        return new DataResponse([
            'calendars' => $calendars,
            'selected' => $selected,
        ]);
    }

    public function saveSelectedCalendars(array $selected): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse([], 401);
        }

        $this->appConfig->setValueString(
            $this->appName,
            $user->getUID() . '_selected_calendars',
            json_encode($selected)
        );

        return new DataResponse(['status' => 'success']);
    }
}
