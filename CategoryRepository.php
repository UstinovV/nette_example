<?php

declare(strict_types = 1);

namespace WorkMarket\App\Model\Faq;

use Kdyby\Doctrine\EntityManager;;
use Kdyby\Doctrine\EntityRepository;
use Kdyby\Doctrine\Mapping\ClassMetadata;
use WorkMarket\App\Model\Languages\Language;
use WorkMarket\Localization\LanguageProvider;


/**
 *
 * @author Valeriy Ustinov <ustinov.vd@gmail.com>
 */
class CategoryRepository extends EntityRepository
{


	/** @var \WorkMarket\Localization\LanguageProvider */
	private $languageProvider;


	/**
	 * @param \Kdyby\Doctrine\EntityManager $em
	 * @param \Kdyby\Doctrine\Mapping\ClassMetadata $class
	 * @param \WorkMarket\Localization\LanguageProvider $languageProvider
	 */
	public function __construct(EntityManager $em, ClassMetadata $class, LanguageProvider $languageProvider)
	{
		parent::__construct($em, $class);

		$this->languageProvider = $languageProvider;
	}


	/**
	 * @param string $id
	 * @return \WorkMarket\App\Model\Faq\Category
	 */
	public function getOneById(string $id): Category
	{
		$category = $this->findOneBy(['id' => $id]);

		if (!$category) {
			throw CategoryNotFoundException::oneById($id);
		}

		return $category;
	}


	/**
	 * @param string $urlTitle
	 * @return \WorkMarket\App\Model\Faq\Category
	 */
	public function getOneByUrlTitle(string $urlTitle): Category
	{
		$category = $this->findOneBy(['urlTitle' => $urlTitle]);

		if (!$category) {
			throw CategoryNotFoundException::oneByUrlTitle($urlTitle);
		}

		return $category;
	}


	/**
	 * @param \WorkMarket\App\Model\Faq\Category $category
	 * @param \WorkMarket\App\Model\Languages\Language $language
	 * @return \WorkMarket\App\Model\Faq\CategoryTranslation|null
	 */
	public function findOneTranslationByCategoryAndLanguage(Category $category, Language $language): ?CategoryTranslation
	{
		return $this->getEntityManager()->createQueryBuilder()
			->select('t')->from(CategoryTranslation::class, 't')
			->where('t.category = :category AND t.language = :language')
			->setParameter('category', $category)
			->setParameter('language', $language)
			->setMaxResults(1)
			->getQuery()
			->getOneOrNullResult();
	}


	/**
	 * @return array
	 */
	public function getList(): array
	{
		$language = $this->languageProvider->getLanguage();

		$categories = $this->getEntityManager()->createQueryBuilder()
			->select('c.id as id, t.title as title')->from(CategoryTranslation::class, 't')
			->join('t.category', 'c')
			->where('t.language = :language')
			->orderBy('c.priority', 'DESC')
			->setParameter('language', $language)
			->getQuery()
			->getArrayResult();

		$result = [];

		foreach ($categories as $category) {
			$result[$category['id']->toString()] = $category['title'];
		}

		return $result;
	}


	/**
	 * @param int|null $type
	 * @return \WorkMarket\App\Model\Faq\Category[]
	 */
	public function getListByPriority(int $type = null): array
	{
		$qb = $this->createQueryBuilder('c')
			->orderBy('c.priority', 'DESC');

		if (!is_null($type)) {
			$qb->where('c.type = :type')
				->setParameter('type', $type);
		}

		return $qb->getQuery()->getResult();
	}

}
