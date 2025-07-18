<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OC\L10N;

use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\L10N\ILanguageIterator;
use function is_null;

/**
 * A factory that generates language instances
 */
class Factory implements IFactory {
	/** @var string */
	protected $requestLanguage = '';

	/**
	 * cached instances
	 * @var array Structure: Lang => App => \OCP\IL10N
	 */
	protected $instances = [];

	/**
	 * @var array Structure: App => string[]
	 */
	protected $availableLanguages = [];

	/**
	 * @var array
	 */
	protected $localeCache = [];

	/**
	 * @var array
	 */
	protected $availableLocales = [];

	/**
	 * @var array Structure: string => callable
	 */
	protected $pluralFunctions = [];

	public const COMMON_LANGUAGE_CODES = [
		'en', 'es', 'fr', 'de', 'de_DE', 'ja', 'ar', 'ru', 'nl', 'it',
		'pt_BR', 'pt_PT', 'da', 'fi_FI', 'nb_NO', 'sv', 'tr', 'zh_CN', 'ko'
	];

	/**
	 * Keep in sync with `build/translation-checker.php`
	 */
	public const RTL_LANGUAGES = [
		'ar', // Arabic
		'fa', // Persian
		'he', // Hebrew
		'ps', // Pashto,
		'ug', // 'Uyghurche / Uyghur
		'ur_PK', // Urdu
	];

	private ICache $cache;

	public function __construct(
		protected IConfig $config,
		protected IRequest $request,
		protected IUserSession $userSession,
		ICacheFactory $cacheFactory,
		protected string $serverRoot,
		protected IAppManager $appManager,
	) {
		$this->cache = $cacheFactory->createLocal('L10NFactory');
	}

	/**
	 * Get a language instance
	 *
	 * @param string $app
	 * @param string|null $lang
	 * @param string|null $locale
	 * @return \OCP\IL10N
	 */
	public function get($app, $lang = null, $locale = null) {
		return new LazyL10N(function () use ($app, $lang, $locale) {
			$app = $this->appManager->cleanAppId($app);
			if ($lang !== null) {
				$lang = str_replace(['\0', '/', '\\', '..'], '', $lang);
			}

			$forceLang = $this->request->getParam('forceLanguage') ?? $this->config->getSystemValue('force_language', false);
			if (is_string($forceLang)) {
				$lang = $forceLang;
			}

			$forceLocale = $this->config->getSystemValue('force_locale', false);
			if (is_string($forceLocale)) {
				$locale = $forceLocale;
			}

			$lang = $this->validateLanguage($app, $lang);

			if ($locale === null || !$this->localeExists($locale)) {
				$locale = $this->findLocale($lang);
			}

			if (!isset($this->instances[$lang][$app])) {
				$this->instances[$lang][$app] = new L10N(
					$this,
					$app,
					$lang,
					$locale,
					$this->getL10nFilesForApp($app, $lang)
				);
			}

			return $this->instances[$lang][$app];
		});
	}

	/**
	 * Check that $lang is an existing language and not null, otherwise return the language to use instead
	 *
	 * @psalm-taint-escape callable
	 * @psalm-taint-escape cookie
	 * @psalm-taint-escape file
	 * @psalm-taint-escape has_quotes
	 * @psalm-taint-escape header
	 * @psalm-taint-escape html
	 * @psalm-taint-escape include
	 * @psalm-taint-escape ldap
	 * @psalm-taint-escape shell
	 * @psalm-taint-escape sql
	 * @psalm-taint-escape unserialize
	 */
	private function validateLanguage(string $app, ?string $lang): string {
		if ($lang === null || !$this->languageExists($app, $lang)) {
			return $this->findLanguage($app);
		} else {
			return $lang;
		}
	}

	/**
	 * Find the best language
	 *
	 * @param string|null $appId App id or null for core
	 *
	 * @return string language If nothing works it returns 'en'
	 */
	public function findLanguage(?string $appId = null): string {
		// Step 1: Forced language always has precedence over anything else
		$forceLang = $this->request->getParam('forceLanguage') ?? $this->config->getSystemValue('force_language', false);
		if (is_string($forceLang)) {
			$this->requestLanguage = $forceLang;
		}

		// Step 2: Return cached language
		if ($this->requestLanguage !== '' && $this->languageExists($appId, $this->requestLanguage)) {
			return $this->requestLanguage;
		}

		/**
		 * Step 3: At this point Nextcloud might not yet be installed and thus the lookup
		 * in the preferences table might fail. For this reason we need to check
		 * whether the instance has already been installed
		 *
		 * @link https://github.com/owncloud/core/issues/21955
		 */
		if ($this->config->getSystemValueBool('installed', false)) {
			$userId = !is_null($this->userSession->getUser()) ? $this->userSession->getUser()->getUID() :  null;
			if (!is_null($userId)) {
				$userLang = $this->config->getUserValue($userId, 'core', 'lang', null);
			} else {
				$userLang = null;
			}
		} else {
			$userId = null;
			$userLang = null;
		}
		if ($userLang) {
			$this->requestLanguage = $userLang;
			if ($this->languageExists($appId, $userLang)) {
				return $userLang;
			}
		}

		// Step 4: Check the request headers
		try {
			// Try to get the language from the Request
			$lang = $this->getLanguageFromRequest($appId);
			if ($userId !== null && $appId === null && !$userLang) {
				$this->config->setUserValue($userId, 'core', 'lang', $lang);
			}
			return $lang;
		} catch (LanguageNotFoundException $e) {
			// Finding language from request failed fall back to default language
			$defaultLanguage = $this->config->getSystemValue('default_language', false);
			if ($defaultLanguage !== false && $this->languageExists($appId, $defaultLanguage)) {
				return $defaultLanguage;
			}
		}

		// Step 5: fall back to English
		return 'en';
	}

	public function findGenericLanguage(?string $appId = null): string {
		// Step 1: Forced language always has precedence over anything else
		$forcedLanguage = $this->request->getParam('forceLanguage') ?? $this->config->getSystemValue('force_language', false);
		if ($forcedLanguage !== false) {
			return $forcedLanguage;
		}

		// Step 2: Check if we have a default language
		$defaultLanguage = $this->config->getSystemValue('default_language', false);
		if ($defaultLanguage !== false && $this->languageExists($appId, $defaultLanguage)) {
			return $defaultLanguage;
		}

		// Step 3.1: Check if Nextcloud is already installed before we try to access user info
		if (!$this->config->getSystemValueBool('installed', false)) {
			return 'en';
		}
		// Step 3.2: Check the current user (if any) for their preferred language
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$userLang = $this->config->getUserValue($user->getUID(), 'core', 'lang', null);
			if ($userLang !== null) {
				return $userLang;
			}
		}

		// Step 4: Check the request headers
		try {
			return $this->getLanguageFromRequest($appId);
		} catch (LanguageNotFoundException $e) {
			// Ignore and continue
		}

		// Step 5: fall back to English
		return 'en';
	}

	/**
	 * find the best locale
	 *
	 * @param string $lang
	 * @return null|string
	 */
	public function findLocale($lang = null) {
		$forceLocale = $this->config->getSystemValue('force_locale', false);
		if (is_string($forceLocale) && $this->localeExists($forceLocale)) {
			return $forceLocale;
		}

		if ($this->config->getSystemValueBool('installed', false)) {
			$userId = $this->userSession->getUser() !== null ? $this->userSession->getUser()->getUID() :  null;
			$userLocale = null;
			if ($userId !== null) {
				$userLocale = $this->config->getUserValue($userId, 'core', 'locale', null);
			}
		} else {
			$userId = null;
			$userLocale = null;
		}

		if ($userLocale && $this->localeExists($userLocale)) {
			return $userLocale;
		}

		// Default : use system default locale
		$defaultLocale = $this->config->getSystemValue('default_locale', false);
		if ($defaultLocale !== false && $this->localeExists($defaultLocale)) {
			return $defaultLocale;
		}

		// If no user locale set, use lang as locale
		if ($lang !== null && $this->localeExists($lang)) {
			return $lang;
		}

		// At last, return USA
		return 'en_US';
	}

	/**
	 * find the matching lang from the locale
	 *
	 * @param string $app
	 * @param string $locale
	 * @return null|string
	 */
	public function findLanguageFromLocale(string $app = 'core', ?string $locale = null) {
		if ($this->languageExists($app, $locale)) {
			return $locale;
		}

		// Try to split e.g: fr_FR => fr
		$locale = explode('_', $locale)[0];
		if ($this->languageExists($app, $locale)) {
			return $locale;
		}
	}

	/**
	 * Find all available languages for an app
	 *
	 * @param string|null $app App id or null for core
	 * @return string[] an array of available languages
	 */
	public function findAvailableLanguages($app = null): array {
		$key = $app;
		if ($key === null) {
			$key = 'null';
		}

		if ($availableLanguages = $this->cache->get($key)) {
			$this->availableLanguages[$key] = $availableLanguages;
		}

		// also works with null as key
		if (!empty($this->availableLanguages[$key])) {
			return $this->availableLanguages[$key];
		}

		$available = ['en']; //english is always available
		$dir = $this->findL10nDir($app);
		if (is_dir($dir)) {
			$files = scandir($dir);
			if ($files !== false) {
				foreach ($files as $file) {
					if (str_ends_with($file, '.json') && !str_starts_with($file, 'l10n')) {
						$available[] = substr($file, 0, -5);
					}
				}
			}
		}

		// merge with translations from theme
		$theme = $this->config->getSystemValueString('theme');
		if (!empty($theme)) {
			$themeDir = $this->serverRoot . '/themes/' . $theme . substr($dir, strlen($this->serverRoot));

			if (is_dir($themeDir)) {
				$files = scandir($themeDir);
				if ($files !== false) {
					foreach ($files as $file) {
						if (str_ends_with($file, '.json') && !str_starts_with($file, 'l10n')) {
							$available[] = substr($file, 0, -5);
						}
					}
				}
			}
		}

		$this->availableLanguages[$key] = $available;
		$this->cache->set($key, $available, 60);
		return $available;
	}

	/**
	 * @return array|mixed
	 */
	public function findAvailableLocales() {
		if (!empty($this->availableLocales)) {
			return $this->availableLocales;
		}

		$localeData = file_get_contents(\OC::$SERVERROOT . '/resources/locales.json');
		$this->availableLocales = \json_decode($localeData, true);

		return $this->availableLocales;
	}

	/**
	 * @param string|null $app App id or null for core
	 * @param string $lang
	 * @return bool
	 */
	public function languageExists($app, $lang) {
		if ($lang === 'en') { //english is always available
			return true;
		}

		$languages = $this->findAvailableLanguages($app);
		return in_array($lang, $languages);
	}

	public function getLanguageDirection(string $language): string {
		if (in_array($language, self::RTL_LANGUAGES, true)) {
			return 'rtl';
		}

		return 'ltr';
	}

	public function getLanguageIterator(?IUser $user = null): ILanguageIterator {
		$user = $user ?? $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('Failed to get an IUser instance');
		}
		return new LanguageIterator($user, $this->config);
	}

	/**
	 * Return the language to use when sending something to a user
	 *
	 * @param IUser|null $user
	 * @return string
	 * @since 20.0.0
	 */
	public function getUserLanguage(?IUser $user = null): string {
		$language = $this->config->getSystemValue('force_language', false);
		if ($language !== false) {
			return $language;
		}

		if ($user instanceof IUser) {
			$language = $this->config->getUserValue($user->getUID(), 'core', 'lang', null);
			if ($language !== null) {
				return $language;
			}

			if (($forcedLanguage = $this->request->getParam('forceLanguage')) !== null) {
				return $forcedLanguage;
			}

			// Use language from request
			if ($this->userSession->getUser() instanceof IUser
				&& $user->getUID() === $this->userSession->getUser()->getUID()) {
				try {
					return $this->getLanguageFromRequest();
				} catch (LanguageNotFoundException $e) {
				}
			}
		}

		return $this->request->getParam('forceLanguage') ?? $this->config->getSystemValueString('default_language', 'en');
	}

	/**
	 * @param string $locale
	 * @return bool
	 */
	public function localeExists($locale) {
		if ($locale === 'en') { //english is always available
			return true;
		}

		if ($this->localeCache === []) {
			$locales = $this->findAvailableLocales();
			foreach ($locales as $l) {
				$this->localeCache[$l['code']] = true;
			}
		}

		return isset($this->localeCache[$locale]);
	}

	/**
	 * @throws LanguageNotFoundException
	 */
	private function getLanguageFromRequest(?string $app = null): string {
		$header = $this->request->getHeader('ACCEPT_LANGUAGE');
		if ($header !== '') {
			$available = $this->findAvailableLanguages($app);

			// E.g. make sure that 'de' is before 'de_DE'.
			sort($available);

			$preferences = preg_split('/,\s*/', strtolower($header));
			foreach ($preferences as $preference) {
				[$preferred_language] = explode(';', $preference);
				$preferred_language = str_replace('-', '_', $preferred_language);

				$preferred_language_parts = explode('_', $preferred_language);
				foreach ($available as $available_language) {
					if ($preferred_language === strtolower($available_language)) {
						return $this->respectDefaultLanguage($app, $available_language);
					}
					if (strtolower($available_language) === $preferred_language_parts[0] . '_' . end($preferred_language_parts)) {
						return $available_language;
					}
				}

				// Fallback from de_De to de
				foreach ($available as $available_language) {
					if (substr($preferred_language, 0, 2) === $available_language) {
						return $available_language;
					}
				}
			}
		}

		throw new LanguageNotFoundException();
	}

	/**
	 * if default language is set to de_DE (formal German) this should be
	 * preferred to 'de' (non-formal German) if possible
	 */
	protected function respectDefaultLanguage(?string $app, string $lang): string {
		$result = $lang;
		$defaultLanguage = $this->config->getSystemValue('default_language', false);

		// use formal version of german ("Sie" instead of "Du") if the default
		// language is set to 'de_DE' if possible
		if (
			is_string($defaultLanguage)
			&& strtolower($lang) === 'de'
			&& strtolower($defaultLanguage) === 'de_de'
			&& $this->languageExists($app, 'de_DE')
		) {
			$result = 'de_DE';
		}

		return $result;
	}

	/**
	 * Checks if $sub is a subdirectory of $parent
	 *
	 * @param string $sub
	 * @param string $parent
	 * @return bool
	 */
	private function isSubDirectory($sub, $parent) {
		// Check whether $sub contains no ".."
		if (str_contains($sub, '..')) {
			return false;
		}

		// Check whether $sub is a subdirectory of $parent
		if (str_starts_with($sub, $parent)) {
			return true;
		}

		return false;
	}

	/**
	 * Get a list of language files that should be loaded
	 *
	 * @return string[]
	 */
	private function getL10nFilesForApp(string $app, string $lang): array {
		$languageFiles = [];

		$i18nDir = $this->findL10nDir($app);
		$transFile = strip_tags($i18nDir) . strip_tags($lang) . '.json';

		if (($this->isSubDirectory($transFile, $this->serverRoot . '/core/l10n/')
				|| $this->isSubDirectory($transFile, $this->serverRoot . '/lib/l10n/')
				|| $this->isSubDirectory($transFile, $this->appManager->getAppPath($app) . '/l10n/'))
			&& file_exists($transFile)
		) {
			// load the translations file
			$languageFiles[] = $transFile;
		}

		// merge with translations from theme
		$theme = $this->config->getSystemValueString('theme');
		if (!empty($theme)) {
			$transFile = $this->serverRoot . '/themes/' . $theme . substr($transFile, strlen($this->serverRoot));
			if (file_exists($transFile)) {
				$languageFiles[] = $transFile;
			}
		}

		return $languageFiles;
	}

	/**
	 * find the l10n directory
	 *
	 * @param string $app App id or empty string for core
	 * @return string directory
	 */
	protected function findL10nDir($app = null) {
		if (in_array($app, ['core', 'lib'])) {
			if (file_exists($this->serverRoot . '/' . $app . '/l10n/')) {
				return $this->serverRoot . '/' . $app . '/l10n/';
			}
		} elseif ($app) {
			try {
				return $this->appManager->getAppPath($app) . '/l10n/';
			} catch (AppPathNotFoundException) {
				/* App not found, continue */
			}
		}
		return $this->serverRoot . '/core/l10n/';
	}

	/**
	 * @inheritDoc
	 */
	public function getLanguages(): array {
		$forceLanguage = $this->config->getSystemValue('force_language', false);
		if ($forceLanguage !== false) {
			$l = $this->get('lib', $forceLanguage);
			$potentialName = $l->t('__language_name__');

			return [
				'commonLanguages' => [[
					'code' => $forceLanguage,
					'name' => $potentialName,
				]],
				'otherLanguages' => [],
			];
		}

		$languageCodes = $this->findAvailableLanguages();
		$reduceToLanguages = $this->config->getSystemValue('reduce_to_languages', []);
		if (!empty($reduceToLanguages)) {
			$languageCodes = array_intersect($languageCodes, $reduceToLanguages);
		}

		$commonLanguages = [];
		$otherLanguages = [];

		foreach ($languageCodes as $lang) {
			$l = $this->get('lib', $lang);
			// TRANSLATORS this is the language name for the language switcher in the personal settings and should be the localized version
			$potentialName = $l->t('__language_name__');
			if ($l->getLanguageCode() === $lang && $potentialName[0] !== '_') { //first check if the language name is in the translation file
				$ln = [
					'code' => $lang,
					'name' => $potentialName
				];
			} elseif ($lang === 'en') {
				$ln = [
					'code' => $lang,
					'name' => 'English (US)'
				];
			} else { //fallback to language code
				$ln = [
					'code' => $lang,
					'name' => $lang
				];
			}

			// put appropriate languages into appropriate arrays, to print them sorted
			// common languages -> divider -> other languages
			if (in_array($lang, self::COMMON_LANGUAGE_CODES)) {
				$commonLanguages[array_search($lang, self::COMMON_LANGUAGE_CODES)] = $ln;
			} else {
				$otherLanguages[] = $ln;
			}
		}

		ksort($commonLanguages);

		// sort now by displayed language not the iso-code
		usort($otherLanguages, function ($a, $b) {
			if ($a['code'] === $a['name'] && $b['code'] !== $b['name']) {
				// If a doesn't have a name, but b does, list b before a
				return 1;
			}
			if ($a['code'] !== $a['name'] && $b['code'] === $b['name']) {
				// If a does have a name, but b doesn't, list a before b
				return -1;
			}
			// Otherwise compare the names
			return strcmp($a['name'], $b['name']);
		});

		return [
			// reset indexes
			'commonLanguages' => array_values($commonLanguages),
			'otherLanguages' => $otherLanguages
		];
	}
}
