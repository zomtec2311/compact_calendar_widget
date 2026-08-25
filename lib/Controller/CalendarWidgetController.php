<?php
/**
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
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

			if (method_exists($this->calendarManager, 'getSubscriptionsForPrincipal')) {
				try {
					$subs = $this->calendarManager->getSubscriptionsForPrincipal($principalUri);
					$userCalendars = array_merge($userCalendars, $subs);
				} catch (\Throwable $e) {
					$this->logger->error('CompactCalendarWidget error: ' . $e->getMessage(), ['exception' => $e]);
				}
			}

			$result = [];
			foreach ($userCalendars as $cal) {
				if (!is_object($cal) || !method_exists($cal, 'getUri')) {
					continue;
				}

				$uri = (string)$cal->getUri();
				$displayName = method_exists($cal, 'getDisplayName') ? $cal->getDisplayName() : $uri;

				$color = method_exists($cal, 'getDisplayColor')
					? $cal->getDisplayColor()
					: (method_exists($cal, 'getCalendarColor') ? $cal->getCalendarColor() : '#82b8d6');

				$colorStr = (string)$color;
				if (!empty($colorStr) && !str_starts_with($colorStr, '#') && !str_starts_with($colorStr, 'rgb')) {
					$colorStr = '#' . $colorStr;
				}

				$key = method_exists($cal, 'getKey') ? (string)$cal->getKey() : (method_exists($cal, 'getId') ? (string)$cal->getId() : $uri);

				$result[] = [
					'id'          => $key,
					'uri'         => $uri,
					'displayname' => $displayName,
					'color'       => !empty($colorStr) ? $colorStr : '#82b8d6',
				];
			}

			return new DataResponse(['calendars' => $result]);
		} catch (\Throwable $e) {
			$this->logger->error('CompactCalendarWidget - Error fetching calendars: ' . $e->getMessage(), ['exception' => $e]);
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

			$startParam = $this->request->getParam('start');
			$endParam = $this->request->getParam('end');
			$dateParam = $this->request->getParam('date');

			if ($startParam && $endParam) {
				$start = new DateTime((string)$startParam . ' 00:00:00', $tz);
				$end = new DateTime((string)$endParam . ' 23:59:59', $tz);
				$start->modify('-7 days');
				$end->modify('+7 days');
			} else {
				$baseDate = $dateParam ? new DateTime((string)$dateParam, $tz) : new DateTime('now', $tz);
				$start = clone $baseDate;
				$end = clone $baseDate;

				switch ($range) {
					case 'day':
						$start->setTime(0, 0, 0);
						$end->setTime(23, 59, 59);
						break;
					case 'month':
						$start->modify('first day of this month')->setTime(0, 0, 0)->modify('-7 days');
						$end->modify('last day of this month')->setTime(23, 59, 59)->modify('+7 days');
						break;
					case 'year':
						$start->modify('first day of January')->setTime(0, 0, 0)->modify('-2 days');
						$end->modify('last day of December')->setTime(23, 59, 59)->modify('+2 days');
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
						$start->modify('-2 days');
						$end->modify('+2 days');
						break;
				}
			}

			$principalUri = 'principals/users/' . $userId;
			if (method_exists($this->calendarManager, 'getCalendarsForPrincipalWithSubscriptions')) {
				$userCalendars = $this->calendarManager->getCalendarsForPrincipalWithSubscriptions($principalUri);
			} elseif (method_exists($this->calendarManager, 'getSubscriptionsForPrincipal')) {
				$calsOnly = $this->calendarManager->getCalendarsForPrincipal($principalUri);
				$subsOnly = $this->calendarManager->getSubscriptionsForPrincipal($principalUri);
				$userCalendars = array_merge($calsOnly, $subsOnly);
			} else {
				$userCalendars = $this->calendarManager->getCalendarsForPrincipal($principalUri);
			}

			$calendarColorMap = [];
			foreach ($userCalendars as $cal) {
				if (!is_object($cal) || !method_exists($cal, 'getUri')) {
					continue;
				}
				$uri = (string)$cal->getUri();
				$key = method_exists($cal, 'getKey') ? (string)$cal->getKey() : (method_exists($cal, 'getId') ? (string)$cal->getId() : $uri);
				$color = method_exists($cal, 'getDisplayColor') ? $cal->getDisplayColor() : (method_exists($cal, 'getCalendarColor') ? $cal->getCalendarColor() : '#82b8d6');

				$calendarColorMap[$uri] = (string)$color;
				$calendarColorMap[$key] = (string)$color;
			}

			$query = $this->calendarManager->newQuery($principalUri);
			$startUtc = clone $start;
			$startUtc->setTimezone(new DateTimeZone('UTC'));
			$endUtc = clone $end;
			$endUtc->setTimezone(new DateTimeZone('UTC'));

			$query->setTimerangeStart(DateTimeImmutable::createFromMutable($startUtc));
			$query->setTimerangeEnd(DateTimeImmutable::createFromMutable($endUtc));

			$searchResults = $this->calendarManager->searchForPrincipal($query);

			if (method_exists($this->calendarManager, 'getSubscriptionsForPrincipal')) {
				try {
					$subscriptions = $this->calendarManager->getSubscriptionsForPrincipal($principalUri);
					foreach ($subscriptions as $sub) {
						$subUri = method_exists($sub, 'getUri') ? (string)$sub->getUri() : '';
						$subKey = method_exists($sub, 'getKey') ? (string)$sub->getKey() : '';
						$subId = method_exists($sub, 'getId') ? (string)$sub->getId() : '';

						$isSubSelected = false;
						if (!$hasStoredConfig || empty($selectedCalendars)) {
							$isSubSelected = true;
						} else {
							foreach ($selectedCalendars as $sel) {
								$sel = (string)$sel;
								if ($sel === $subUri || $sel === $subKey || $sel === $subId ||
									($subUri !== '' && str_contains($subUri, $sel)) ||
									($sel !== '' && str_contains($sel, $subUri))) {
									$isSubSelected = true;
									break;
								}
							}
						}

						if (!$isSubSelected) {
							continue;
						}

						$subResults = [];
						if (method_exists($this->calendarManager, 'searchForSubscription')) {
							try {
								$subResults = $this->calendarManager->searchForSubscription($sub, $query);
							} catch (\Throwable $e) {
								$subResults = [];
							}
						}

						if (empty($subResults) && method_exists($sub, 'getObjects')) {
							try {
								$rawObjects = $sub->getObjects();
								if (!empty($rawObjects)) {
									$subResults[] = [
										'calendar-uri' => $subUri,
										'calendar-key' => $subKey,
										'id'           => $subId,
										'objects'      => $rawObjects,
									];
								}
							} catch (\Throwable $e) {
							}
						}

						if (is_array($subResults)) {
							foreach ($subResults as &$subRes) {
								$subRes['calendar-uri'] = !empty($subUri) ? $subUri : ($subRes['calendar-uri'] ?? 'feiertage-in-germany');
								$subRes['calendar-key'] = !empty($subKey) ? $subKey : ($subRes['calendar-key'] ?? $subId);
								$subRes['is_subscription'] = true;
							}
							unset($subRes);
							$searchResults = array_merge($searchResults, $subResults);
						}
					}
				} catch (\Throwable $subEx) {
					$this->logger->error('[CompactCalendarWidget] Subscriptions Search Fehler: ' . $subEx->getMessage());
				}
			}

			$events = [];
			foreach ($searchResults as $item) {
				$calKey = (string)($item['calendar-key'] ?? '');
				$calUri = (string)($item['calendar-uri'] ?? '');
				$calId = (string)($item['id'] ?? '');

				if ($hasStoredConfig && !empty($selectedCalendars)) {
					$matched = false;
					foreach ($selectedCalendars as $sel) {
						$sel = trim((string)$sel, '/');
						$checkKey = trim($calKey, '/');
						$checkUri = trim($calUri, '/');
						$checkId = trim($calId, '/');

						if (
							$sel === $checkKey ||
							$sel === $checkUri ||
							$sel === $checkId ||
							($checkUri !== '' && str_contains($checkUri, $sel)) ||
							($sel !== '' && str_contains($sel, $checkUri)) ||
							($checkKey !== '' && str_contains($checkKey, $sel))
						) {
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
					$summary = 'Kein Titel';
					if (isset($obj['SUMMARY'])) {
						if (is_array($obj['SUMMARY'])) {
							$summary = is_array($obj['SUMMARY'][0]) ? ($obj['SUMMARY'][0]['value'] ?? 'Kein Titel') : $obj['SUMMARY'][0];
						} else {
							$summary = (string)$obj['SUMMARY'];
						}
					}

					$dtStartRaw = null;
					$dtStartParams = [];
					if (isset($obj['DTSTART'])) {
						if (is_array($obj['DTSTART'])) {
							$dtStartRaw = $obj['DTSTART'][0] ?? null;
							$dtStartParams = $obj['DTSTART'][1] ?? [];
						} else {
							$dtStartRaw = $obj['DTSTART'];
						}
					}

					if (!$dtStartRaw) {
						continue;
					}

					$isAllDay = false;
					if ((isset($dtStartParams['VALUE']) && strtoupper((string)$dtStartParams['VALUE']) === 'DATE') ||
						(is_string($dtStartRaw) && strlen(trim((string)$dtStartRaw)) === 8)) {
						$isAllDay = true;
					}

					try {
						if ($dtStartRaw instanceof DateTimeInterface) {
							$dtStart = DateTime::createFromInterface($dtStartRaw);
						} else {
							$cleanStart = trim((string)$dtStartRaw);
							if (strlen($cleanStart) === 8 && is_numeric($cleanStart)) {
								$dtStart = DateTime::createFromFormat('Ymd', $cleanStart, $tz);
							} else {
								$dtStart = new DateTime($cleanStart);
							}
						}
					} catch (\Throwable $e) {
						continue;
					}

					if ($isAllDay) {
						$dtStart->setTime(0, 0, 0);
					} else {
						$dtStart->setTimezone($tz);
					}

					$dtEndRaw = null;
					if (isset($obj['DTEND'])) {
						$dtEndRaw = is_array($obj['DTEND']) ? ($obj['DTEND'][0] ?? null) : $obj['DTEND'];
					}

					try {
						if ($dtEndRaw instanceof DateTimeInterface) {
							$dtEnd = DateTime::createFromInterface($dtEndRaw);
						} elseif (!empty($dtEndRaw)) {
							$cleanEnd = trim((string)$dtEndRaw);
							if (strlen($cleanEnd) === 8 && is_numeric($cleanEnd)) {
								$dtEnd = DateTime::createFromFormat('Ymd', $cleanEnd, $tz);
							} else {
								$dtEnd = new DateTime($cleanEnd);
							}
						} else {
							$dtEnd = clone $dtStart;
						}
					} catch (\Throwable $e) {
						$dtEnd = clone $dtStart;
					}

					if ($isAllDay) {
						$dtEnd->setTime(23, 59, 59);
					} else {
						$dtEnd->setTimezone($tz);
					}

					$uidRaw = isset($obj['UID']) ? (is_array($obj['UID']) ? ($obj['UID'][0] ?? null) : $obj['UID']) : null;
					$eventId = (string)($uidRaw ?? ($item['id'] ?? uniqid()));
					$instanceId = $eventId . '_' . $dtStart->format('YmdHis');

					$events[] = [
						'id' => $instanceId,
						'title' => (string)$summary,
						'startDate' => $dtStart->format('Y-m-d'),
						'start' => $dtStart->format('c'),
						'end' => $dtEnd->format('c'),
						'location' => (string)(is_array($obj['LOCATION'] ?? null) ? ($obj['LOCATION'][0] ?? '') : ($obj['LOCATION'] ?? '')),
						'allDay' => $isAllDay,
						'color' => $colorStr,
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
			$this->logger->error('CompactCalendarWidget error: ' . $e->getMessage(), ['exception' => $e]);
			return new DataResponse([
				'error' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine()
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
