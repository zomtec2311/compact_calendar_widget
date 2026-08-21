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
use OCP\IDBConnection;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Reader;
use OCP\Config\IUserConfig;
use OCP\Calendar\IManager;
use OCP\Calendar\ICalendar;
use Psr\Log\LoggerInterface;

class CalendarWidgetController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private IDBConnection $db,
        private IUserConfig $userConfig,
        private readonly LoggerInterface $logger,
        private IManager $calendarManager
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getEvents(string $range = 'week'): DataResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new DataResponse([], 401);
            }

            $userId = $user->getUID();
            $tz = new DateTimeZone(date_default_timezone_get());

            $rawSelected = $this->userConfig->getValueString($userId, $this->appName, 'selected_calendars', '');
            if (empty($rawSelected)) {
                $rawSelected = $this->userConfig->getValueString($userId, $this->appName, 'selectedCalendars', '');
            }
            if (empty($rawSelected)) {
                $rawSelected = $this->userConfig->getValueString($userId, $this->appName, 'calendars', '');
            }

            $selectedCalendars = [];
            $hasStoredConfig = !empty($rawSelected);

            if ($hasStoredConfig) {
                $decoded = json_decode($rawSelected, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $item) {
                        if (is_array($item)) {
                            if (isset($item['uri'])) $selectedCalendars[] = (string)$item['uri'];
                            elseif (isset($item['id'])) $selectedCalendars[] = (string)$item['id'];
                            elseif (isset($item['value'])) $selectedCalendars[] = (string)$item['value'];
                        } else {
                            $selectedCalendars[] = (string)$item;
                        }
                    }
                }
            }
            $selectedCalendars = array_filter(array_unique($selectedCalendars));

            if ($hasStoredConfig && empty($selectedCalendars)) {
                return new DataResponse([]);
            }

            $calendarColors = [];
            try {
                $userCalendars = $this->calendarManager->getCalendars();
                foreach ($userCalendars as $cal) {
                    if (!$cal instanceof ICalendar) continue;
                    $color = method_exists($cal, 'getColor') ? (string)$cal->getColor() : '#0082c9';
                    if (method_exists($cal, 'getUri')) $calendarColors[(string)$cal->getUri()] = $color;
                    if (method_exists($cal, 'getKey')) $calendarColors[(string)$cal->getKey()] = $color;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('CalendarWidget: Manager failed to fetch colors', ['exception' => $e]);
            }

            $startParam = $this->request->getParam('start');
            $endParam   = $this->request->getParam('end');
            $dateParam  = $this->request->getParam('date');

            if ($startParam && $endParam) {
                $start = new DateTime((string)$startParam . ' 00:00:00', $tz);
                $end   = new DateTime((string)$endParam . ' 23:59:59', $tz);
            } else {
                $baseDate = $dateParam ? new DateTime((string)$dateParam, $tz) : new DateTime('now', $tz);
                $start    = clone $baseDate;
                $end      = clone $baseDate;

                switch ($range) {
                    case 'day':
                        $start->setTime(0, 0, 0);
                        $end->setTime(23, 59, 59);
                        break;
                    case 'month':
                        $start->modify('first day of this month')->setTime(0, 0, 0);
                        $end->modify('last day of this month')->setTime(23, 59, 59);
                        break;
                    case 'year':
                        $start->modify('first day of January')->setTime(0, 0, 0);
                        $end->modify('last day of December')->setTime(23, 59, 59);
                        break;
                    case 'week':
                    default:
                        if ($start->format('N') === '1') {
                            $start->setTime(0, 0, 0);
                        } else {
                            $start->modify('last monday')->setTime(0, 0, 0);
                        }
                        $end = clone $start;
                        $end->modify('+6 days')->setTime(23, 59, 59);
                        break;
                }
            }

            $sql = 'SELECT co.calendardata, c.uri as calendar_uri, c.id as calendar_id
                    FROM `*PREFIX*calendarobjects` co
                    JOIN `*PREFIX*calendars` c ON co.calendarid = c.id
                    WHERE co.componenttype = \'VEVENT\'';

            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute();
            $rows = $result->fetchAll();
            $result->closeCursor();

            $events = [];
            $utcTz = new DateTimeZone('UTC');
            $expandStart = new DateTimeImmutable($start->format('Y-m-d H:i:s'), $tz);
            $expandEnd   = new DateTimeImmutable($end->format('Y-m-d H:i:s'), $tz);

            foreach ($rows as $row) {
                $calendarData = $row['calendardata'] ?? null;
                $calUri       = (string)($row['calendar_uri'] ?? '');
                $calId        = (string)($row['calendar_id'] ?? '');

                if ($hasStoredConfig && !empty($selectedCalendars)) {
                    $matched = false;
                    foreach ($selectedCalendars as $sel) {
                        if ($sel === $calUri || $sel === $calId || str_ends_with($calUri, '/' . $sel) || str_contains($calUri, $sel)) {
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        continue;
                    }
                }

                if (!$calendarData || !is_string($calendarData)) {
                    continue;
                }

                try {
                    $vObject = Reader::read($calendarData);
                    if (!isset($vObject->VEVENT)) continue;

                    $veventsToProcess = [];
                    try {
                        $cloned = clone $vObject;
                        $expanded = $cloned->expand($expandStart->setTimezone($utcTz), $expandEnd->setTimezone($utcTz));
                        if ($expanded && isset($expanded->VEVENT)) {
                            foreach ($expanded->VEVENT as $ev) {
                                $veventsToProcess[] = $ev;
                            }
                        }
                    } catch (\Throwable $e) {
                        foreach ($vObject->VEVENT as $ev) {
                            $veventsToProcess[] = $ev;
                        }
                    }

                    foreach ($veventsToProcess as $vevent) {
                        $status = strtoupper((string)($vevent->STATUS ?? ''));
                        if ($status === 'CANCELLED' || $status === 'DELETED') continue;

                        $dtStartProp = $vevent->DTSTART ?? null;
                        if (!$dtStartProp) continue;

                        /** @var \DateTime $dtStart */
                        $dtStart = $dtStartProp->getDateTime();
                        $dtEndProp = $vevent->DTEND ?? null;
                        $dtEnd = $dtEndProp ? $dtEndProp->getDateTime() : null;

                        $dtStart->setTimezone($tz);
                        if ($dtEnd) $dtEnd->setTimezone($tz);

                        $isAllDay = false;
                        if (isset($dtStartProp['VALUE']) && (string)$dtStartProp['VALUE'] === 'DATE') {
                            $isAllDay = true;
                            $dtStart = new DateTime($dtStart->format('Y-m-d') . ' 00:00:00', $tz);
                            if ($dtEnd) {
                                $dtEnd = new DateTime($dtEnd->format('Y-m-d') . ' 00:00:00', $tz);
                                $dtEnd->modify('-1 second');
                            } else {
                                $dtEnd = clone $dtStart;
                                $dtEnd->setTime(23, 59, 59);
                            }
                        }

                        $effectiveEnd = $dtEnd ?? $dtStart;

                        if ($dtStart <= $end && $effectiveEnd >= $start) {
                            $uid = (string)($vevent->UID ?? uniqid());
                            $instanceId = $uid . '_' . $dtStart->format('YmdHis');

                            $events[] = [
                                'id'       => $instanceId,
                                'title'    => (string)($vevent->SUMMARY ?? 'Kein Titel'),
                                'start'    => $dtStart->format('c'),
                                'end'      => $effectiveEnd->format('c'),
                                'location' => (string)($vevent->LOCATION ?? ''),
                                'allDay'   => $isAllDay,
                                'color'    => $calendarColors[$calUri] ?? $calendarColors[$calId] ?? '#0082c9',
                            ];
                        }
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }

            $uniqueEvents = [];
            foreach ($events as $ev) {
                $uniqueEvents[$ev['id']] = $ev;
            }
            $events = array_values($uniqueEvents);

            usort($events, fn($a, $b) => strcmp($a['start'], $b['start']));

            return new DataResponse($events);

        } catch (\Throwable $e) {
            return new DataResponse([
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine()
            ], 500);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getSetting(?string $key = null): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) return new DataResponse(['error' => 'Unauthorized'], 401);

        $key = $key ?? (string)$this->request->getParam('key', 'defaultView');
        $value = $this->userConfig->getValueString($user->getUID(), $this->appName, $key, '');

        return new DataResponse(['key' => $key, 'value' => $value]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function saveSetting(?string $key = null, ?string $value = null): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) return new DataResponse(['error' => 'Unauthorized'], 401);

        $params = $this->request->getParams();
        $key = $key ?? $params['key'] ?? null;
        $value = $value ?? $params['value'] ?? null;

        if (empty($key) || $value === null) {
            return new DataResponse(['error' => 'Missing parameter'], 400);
        }

        $this->userConfig->setValueString($user->getUID(), $this->appName, (string)$key, (string)$value);
        return new DataResponse(['success' => true]);
    }
}
