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
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use DateTimeInterface;
use OCP\Config\IUserConfig;
use OCP\Calendar\IManager;
use Psr\Log\LoggerInterface;

class CalendarWidgetController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private IUserConfig $userConfig,
		private readonly LoggerInterface $logger,
		private IManager $calendarManager,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getCalendars(): DataResponse {
		try {
			$user = $this->userSession->getUser();
			if (!$user) {
				return new DataResponse([], 401);
			}

			$principalUri = 'principals/users/' . $user->getUID();
			$userCalendars = $this->calendarManager->getCalendarsForPrincipal($principalUri);

			$result = [];
			foreach ($userCalendars as $cal) {
				$uri = (string)$cal->getUri();
				$displayName = method_exists($cal, 'getDisplayName') ? $cal->getDisplayName() : $uri;

				$color = method_exists($cal, 'getDisplayColor')
					? $cal->getDisplayColor()
					: (method_exists($cal, 'getCalendarColor') ? $cal->getCalendarColor() : '#82b8d6');

				$colorStr = (string)$color;
				if (!empty($colorStr) && !str_starts_with($colorStr, '#') && !str_starts_with($colorStr, 'rgb')) {
					$colorStr = '#' . $colorStr;
				}

				$result[] = [
					'id'          => (string)$cal->getKey(),
					'uri'         => $uri,
					'displayname' => $displayName,
					'color'       => !empty($colorStr) ? $colorStr : '#82b8d6',
				];
			}

			return new DataResponse(['calendars' => $result]);
		} catch (\Throwable $e) {
			$this->logger->error('Error fetching calendars: ' . $e->getMessage(), ['exception' => $e]);
			return new DataResponse(['error' => $e->getMessage()], 500);
		}
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

			$rawSelected = $this->userConfig->getValueString($userId, $this->appName, 'selected_calendars', 'null');
			if ($rawSelected === 'null' || empty($rawSelected)) {
				$rawSelected = $this->userConfig->getValueString($userId, $this->appName, 'selectedCalendars', 'null');
			}
			if ($rawSelected === 'null' || empty($rawSelected)) {
				$rawSelected = $this->userConfig->getValueString($userId, $this->appName, 'calendars', 'null');
			}

			$selectedCalendars = [];
			$hasStoredConfig = ($rawSelected !== 'null' && !empty($rawSelected));

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

			$principalUri = 'principals/users/' . $userId;
			$userCalendars = $this->calendarManager->getCalendarsForPrincipal($principalUri);

			$calendarColorMap = [];
			foreach ($userCalendars as $cal) {
				$uri = (string)$cal->getUri();
				$key = (string)$cal->getKey();

				$color = method_exists($cal, 'getDisplayColor')
					? $cal->getDisplayColor()
					: (method_exists($cal, 'getCalendarColor') ? $cal->getCalendarColor() : '#82b8d6');

				$colorStr = (string)$color;
				if (!empty($colorStr) && !str_starts_with($colorStr, '#') && !str_starts_with($colorStr, 'rgb')) {
					$colorStr = '#' . $colorStr;
				}
				if (empty($colorStr)) {
					$colorStr = '#82b8d6';
				}

				$calendarColorMap[$uri] = $colorStr;
				$calendarColorMap[$key] = $colorStr;
			}

			$query = $this->calendarManager->newQuery($principalUri);

			$startUtc = clone $start;
			$startUtc->setTimezone(new DateTimeZone('UTC'));
			$endUtc = clone $end;
			$endUtc->setTimezone(new DateTimeZone('UTC'));

			$query->setTimerangeStart(DateTimeImmutable::createFromMutable($startUtc));
			$query->setTimerangeEnd(DateTimeImmutable::createFromMutable($endUtc));

			$searchResults = $this->calendarManager->searchForPrincipal($query);

			$events = [];

			foreach ($searchResults as $item) {
				$calKey = (string)($item['calendar-key'] ?? '');
				$calUri = (string)($item['calendar-uri'] ?? $calKey);

				if ($hasStoredConfig && !empty($selectedCalendars)) {
					$matched = false;
					foreach ($selectedCalendars as $sel) {
						if ($sel === $calKey || $sel === $calUri || str_contains($calUri, $sel)) {
							$matched = true;
							break;
						}
					}
					if (!$matched) {
						continue;
					}
				}

				$colorStr = $calendarColorMap[$calUri] ?? $calendarColorMap[$calKey] ?? '#82b8d6';
				$objects = $item['objects'] ?? [];

				foreach ($objects as $obj) {
					$summary  = $obj['SUMMARY'][0] ?? 'Kein Titel';
					$location = $obj['LOCATION'][0] ?? '';

					$dtStartRaw = $obj['DTSTART'][0] ?? null;
					$dtEndRaw   = $obj['DTEND'][0] ?? null;

					if (!$dtStartRaw) {
						continue;
					}

					$isAllDay = false;
					$dtStartParams = $obj['DTSTART'][1] ?? [];
					if (isset($dtStartParams['VALUE']) && $dtStartParams['VALUE'] === 'DATE') {
						$isAllDay = true;
					}

					if ($dtStartRaw instanceof DateTimeInterface) {
						$dtStart = DateTime::createFromInterface($dtStartRaw);
					} else {
						$dtStart = new DateTime((string)$dtStartRaw);
					}

					if (!$isAllDay) {
						$dtStart->setTimezone($tz);
					}

					if ($dtEndRaw instanceof DateTimeInterface) {
						$dtEnd = DateTime::createFromInterface($dtEndRaw);
						if (!$isAllDay) $dtEnd->setTimezone($tz);
					} elseif (!empty($dtEndRaw)) {
						$dtEnd = new DateTime((string)$dtEndRaw);
						if (!$isAllDay) $dtEnd->setTimezone($tz);
					} else {
						$dtEnd = clone $dtStart;
					}

					$eventId = (string)($obj['UID'][0] ?? ($item['id'] ?? uniqid()));
					$instanceId = $eventId . '_' . $dtStart->format('YmdHis');

					$events[] = [
						'id'            => $instanceId,
						'title'         => (string)$summary,
						'startDate'     => $dtStart->format('Y-m-d'),
						'start'         => $dtStart->format('c'),
						'end'           => $dtEnd->format('c'),
						'location'      => (string)$location,
						'allDay'        => $isAllDay,
						'color'         => $colorStr,
						'calendarColor' => $colorStr,
					];
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
			$this->logger->error('CalendarWidgetController error: ' . $e->getMessage(), ['exception' => $e]);
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
