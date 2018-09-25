<?php

declare(strict_types = 1);

namespace WorkMarket\App\Commands\Emails;

use WorkMarket\App\Model\Locations\Location;
use WorkMarket\App\Model\Locations\LocationsFacade;
use WorkMarket\Utils\Config;
use WorkMarket\App\Model\Languages\LanguagesFacade;
use WorkMarket\App\Model\Agents\AgentFacade;
use WorkMarket\App\Model\Agents\Agent;
use WorkMarket\App\Model\Professions\ProfessionsFacade;
use WorkMarket\App\Model\Offers\OffersFacade;
use WorkMarket\App\Model\Offerors\OfferorsFacade;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WorkMarket\App\Mailing\MailsRepository;
use WorkMarket\App\Mailing\MailTemplateFactory;
use WorkMarket\Localization\DomainProvider;
use Nette\DI\Config\Loader;
use Elastica;
use WorkMarket\App\Model\Offers\Elasticsearch\OffersMapping;
use DateTime;
use WorkMarket\Mailgun\Mailgun;
use Symfony\Component\Console\Input\InputOption;


/**
 *
 * @author Valeriy Ustinov <ustinov.vd@gmail.com>
 */
class AgentsCommand extends Command
{


	/** @var \WorkMarket\App\Model\Offers\OffersFacade */
	private $offers;

	/** @var \WorkMarket\App\Model\Offerors\OfferorsFacade */
	private $offerors;

	/** @var \WorkMarket\App\Model\Languages\LanguagesFacade */
	private $languages;

	/** @var \WorkMarket\App\Model\Agents\AgentFacade */
	private $agents;

	/** @var \WorkMarket\App\Model\Locations\LocationsFacade */
	private $locationsFacade;

	/** @var \WorkMarket\App\Model\Professions\ProfessionsFacade */
	private $professionsFacade;

	/** @var \WorkMarket\App\Mailing\MailsRepository */
	private $mails;

	/** @var \WorkMarket\App\Mailing\MailTemplateFactory */
	private $mailTemplates;

	/** @var \WorkMarket\Mailgun\Mailgun */
	private $sender;

	/**@var \WorkMarket\Localization\DomainProvider */
	private $domainProvider;

	/** @var \Elastica\Client */
	private $elastica;


	/**
	 * @param \WorkMarket\App\Model\Offers\OffersFacade $offers
	 * @param \WorkMarket\App\Model\Offerors\OfferorsFacade $offerors
	 * @param \WorkMarket\App\Model\Languages\LanguagesFacade $languages
	 * @param \WorkMarket\App\Model\Agents\AgentFacade $agents
	 * @param \WorkMarket\App\Model\Locations\LocationsFacade $locationsFacade
	 * @param \WorkMarket\App\Model\Professions\ProfessionsFacade $professionsFacade
	 * @param \WorkMarket\App\Mailing\MailsRepository $mails
	 * @param \WorkMarket\App\Mailing\MailTemplateFactory $mailTemplates
	 * @param \WorkMarket\Mailgun\Mailgun $sender
	 * @param \WorkMarket\Localization\DomainProvider $domainProvider
	 */
	public function __construct(
		OffersFacade $offers,
		OfferorsFacade $offerors,
		LanguagesFacade $languages,
		AgentFacade $agents,
		LocationsFacade $locationsFacade,
		ProfessionsFacade $professionsFacade,
		MailsRepository $mails,
		MailTemplateFactory $mailTemplates,
		Mailgun $sender,
		DomainProvider $domainProvider
	)
	{
		parent::__construct();

		$this->offers = $offers;
		$this->offerors = $offerors;
		$this->languages = $languages;
		$this->agents = $agents;
		$this->locationsFacade = $locationsFacade;
		$this->professionsFacade = $professionsFacade;
		$this->mails = $mails;
		$this->mailTemplates = $mailTemplates;
		$this->sender = $sender;
		$this->domainProvider = $domainProvider;
		$this->elastica = new Elastica\Client(Config::get('elastic'));

	}


	protected function configure(): void
	{
		$this->setName('app:agents:send')
			->setDescription('Send notifications about new offers')
			->addOption('force', '-f', InputOption::VALUE_NONE, 'Force launch even if current server is not production');
	}


	/**
	 * @param \Symfony\Component\Console\Input\InputInterface $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @return int
	 */
	public function execute(InputInterface $input, OutputInterface $output): int
	{
		$force = $input->getOption('force');
		if (empty($_ENV['ENVIRONMENT']) || $_ENV['ENVIRONMENT'] != 'PRODUCTION') {
			if(!$force){
				$output->writeln('This command allowed only on production. To execute it anyway, use -f option');
				return 0;
			}
		};

		/** @var \WorkMarket\App\Model\Domains\Domain $domain */
		$domain = $this->domainProvider->getListByPriority()[0];
		$now = new DateTime();
		$output->writeln('Agent email sending was started at '. $now->format('Y-m-d H:i:s'));

		$configLoader = new Loader();
		$translations = $configLoader->load(__DIR__. '/../../FrontModule/locale/mail.'.$domain->getLanguage()->getCode(). '.neon');
		
		$mail = $this->mails->getOneByName('agent/mailing');

		$query = [

			'bool' => [

				'filter' => [
					[
						'term' => [
							'domainId' => $domain->getId(),
						],
					],
					[
						'exists' => [
							'field' => 'profession'
						]
					],
					[
						'term' => [
							'isPublished' => true
						],
					],
					[
						'range' => [
							'createdAt' => ['gte'=>'now-1d/d']
						],
					]

				],

				'minimum_should_match' => 0
			],

		];

		$data = [
			'query' => $query,
			'stored_fields' => [
				'title', 'profession', 'location', 'offerorName', 'rating', 'days', 'pricing_type', 'shortId'
			],
			'sort' => [
				'rating' => [
					'order' => 'desc',
				],
				'days' => [
					'order' => 'desc',
				],
				'createdAt' => [
					'order' => 'desc',
				],
			],
			'from' => 0,
			'size' => 60,
		];

		$agentsList = $this->agents->getListByStatus(true);


		foreach ($agentsList as $agent) {
			if (!$agent->getEmail()->getConfirmed()) {
				continue;
			}

			$showAll = '';

			$agentQuery = $query;
			$output->writeln($agent->getType());

			if($agent->getType() == Agent::TYPE_VACANCIES) {
				$showAll .= '/jobs';
				$term = [
					'terms' => [
						'type' => [0,1]
					],
				];
			} else if ($agent->getType() == Agent::TYPE_CV) {
				$showAll .= '/cv';
				$term = [
					'terms' => [
						'type' => [2]
					],
				];
			} else {
				$term = [
					'terms' => [
						'type' => [0,1]
					],
				];
			}

			$agentQuery['bool']['filter'][] = $term;

			$output->writeln('Email '. $agent->getEmail()->getEmail());
			$output->writeln('Agent '. $agent->getId());

			$agentLocations = $this->agents->getAgentLocations($agent->getId());
			$agentProfessions = $this->agents->getAgentProfessions($agent->getId());
			$agentKeywords = $this->agents->getAgentKeywords($agent->getId());

			$locationsTitles = [];
			$locationsIds = [];

			if ($agentLocations) {
				$locations = [];
				foreach ($agentLocations as $id => $title) {
					if ($id != (string) Location::WORLDWIDE) {
						$locations[] = [
							"match" => [
									"locations.id" => $id
								]
							];
						$locationsIds[] = $id;
						$locationsTitles[] = $title;

					}
				}

				if ($locations) {
					$agentQuery['bool']['should'][] = [
						"nested" => [
							"path" => "locations",
							"query" => [
								"bool" => [
									"should" => $locations,
									"minimum_should_match" => 1
								]
							]
						]
					];
				} else {
					$agentQuery['bool']['should'][] = [
						'bool' => [
							'must_not' => [
								'exists' => [
									'field' => 'location',
								]
							]
						]
					];
				}

				if ($locationsIds) {
					$showAll .= '?location='. implode('.', $locationsIds). '/';
				}

				$agentQuery['bool']['minimum_should_match']++;
			}

			$professionsIds = [];

			if ($agentProfessions) {
				foreach ($agentProfessions as $id => $title) {
					$agentQuery['bool']['should'][] = [
						'term' => [
							'professionId' => (string)$id,
						],
					];
					$professionsIds[] = (string) $id;
				}

				if ($professionsIds) {
					$showAll .= strlen($showAll) > 5 ? '&' : '?';
					$showAll .= 'profession='. implode('.', $professionsIds). '/';
				}

				$agentQuery['bool']['minimum_should_match']++;
			}

			if ($agentKeywords) {
				$showAll .= $agentKeywords;
				$agentQuery['bool']['must'] = [
					'multi_match' => [
						'query' => $agentKeywords,
						'type' => 'cross_fields',
						'fields' => ['location', 'profession', 'title', 'content', 'offerorName'],
						'operator' => 'and'
					],
				];
			}

			$path = OffersMapping::INDEX_NAME_PREFIX. '-*/_search';
			$data['query'] = $agentQuery;

			if ($agent->getLanguages()) {
				$path = '/_search';

				foreach ($agent->getLanguages() as $lang) {
					$comma = $path != '/_search' ? ',' : '';
					$path = OffersMapping::INDEX_NAME_PREFIX. '-'. $lang->getCode(). $comma. $path;
				}
			}

			$response = $this->elastica->request(
				$path,
				Elastica\Request::GET,
				$data
			);


			$output->writeln('Vacancies found '. $response->getData()['hits']['total']);

			if ($response->getData()['hits']['total']) {

				$count = (int)$response->getData()['hits']['total'] <= 60 ? $response->getData()['hits']['total']: 60;

				if ($agent->getType() == Agent::TYPE_VACANCIES){
					$tagType = 'Agent vacancies';
					if($count % 10 == 1 && $count % 100 != 11) {
						$subject = $count.' новая вакансия за ';
						$offersCount = 'вакансия';
					}
					else if (in_array($count % 10, [2,3,4])  && ($count % 100 > 10 && $count % 100 < 19)) {
						$subject = $count.' новых вакансий за ';
						$offersCount = 'вакансий';
					}else if (in_array($count % 10, [2,3,4])  && ($count % 100 < 10 || $count % 100 > 19)) {
						$subject = $count.' новые вакансии за ';
						$offersCount = 'вакансии';
					} else {
						$subject = $count.' новых вакансий за ';
						$offersCount = 'вакансий';
					}
					$translations['maling']['header'] = $translations['maling']['headerVacancies'];
				} elseif ($agent->getType() == Agent::TYPE_CV) {
					$tagType = 'Agent CV';
					if($count % 10 == 1) {
						$subject = $count.' новое резюме за ';
					} else {
						$subject = $count.' новых резюме за ';
					}
					$offersCount = 'резюме';
					$translations['maling']['header'] = $translations['maling']['headerCV'];
				} else {
					$tagType = 'Agent vacancies';
					if ($count % 10 == 1 && $count % 100 != 11) {
						$subject = $count.' новая вакансия за ';
						$offersCount = 'ваканся';
					} else if (in_array($count % 10, [2,3,4])  && ($count % 100 > 10 && $count % 100 < 19)) {
						$subject = $count.' новых вакансий за ';
						$offersCount = 'вакансий';
					} else if (in_array($count % 10, [2,3,4])  && ($count % 100 < 10 || $count % 100 > 19)) {
						$subject = $count.' новые вакансии за ';
						$offersCount = 'вакансии';
					} else {
						$subject = $count.' новых вакансий за ';
						$offersCount = 'вакансий';
					}
					$translations['maling']['header'] = $translations['maling']['headerVacancies'];
				}

				$unsubscribe = [
					'email' => $agent->getEmail()->getEmail(),
					'code' => sha1($agent->getId())
				];

				$translations['maling']['offersCount'] = $offersCount;
				$mailParams = [
					'showAll' => $showAll,
					'unsubscribe' => $unsubscribe,
					'offers' => $response->getData()['hits']['hits'],
					'domain' => $domain->getName(),
					'translations' => $translations['maling']
				];

				$images = [
					'logo.png' => $this->mails->getBasePath(). '/agent/images/logo.png',
					'logo-fb.png' => $this->mails->getBasePath(). '/agent/images/logo-fb.png',
					'logo-vk.png' => $this->mails->getBasePath(). '/agent/images/logo-vk.png',
				];

				$subject.= date("d.m.y");

				if ($agentKeywords || $locationsTitles) {
					$subject .= ' - ';
					$subject .= $agentKeywords ? $agentKeywords : '';
					if ($locationsTitles){
						if ($agentKeywords){
							$subject .= ', ';
						}
						$subject .= implode(',', $locationsTitles);
					}

				}

				$html = $this->mailTemplates->generateHtml($mail, $mailParams);
				$this->sender->send('Workmarket.eu <info@workmarket.eu>', $agent->getEmail()->getEmail(), $subject, null, $html, $images, ['Agent', 'Agent mailing', $tagType]);
			}
		}

		$now = new DateTime();
		$output->writeln('Agent email sending was ended at '. $now->format('Y-m-d H:i:s'));

		return 0;
	}

}
