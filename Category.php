<?php

declare(strict_types = 1);

namespace WorkMarket\App\Model\Faq;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping as ORM;
use Kdyby\StrictObjects\Scream;
use WorkMarket\App\Model\Languages\Language;
use WorkMarket\App\Model\Lists\BaseLists\BaseListEntity;
use Ramsey\Uuid\Uuid;
use WorkMarket\App\Model\Lists\CompanyType\CompanyTypeTranslation;
use WorkMarket\InvalidArgumentException;
use WorkMarket\Utils\Strings;


/**
 * @ORM\Entity(repositoryClass="WorkMarket\App\Model\Faq\CategoryRepository")
 * @ORM\Table(name="faq_category")
 *
 * @author Valeriy Ustinov <ustinov.vd@gmail.com>
 */
class Category extends BaseListEntity
{


	use Scream;


	public const TYPE_EMPLOYER = 0;
	public const TYPE_JOBSEEKER = 2;


	const URL_TITLE_PATTERN = '^[a-zA-Z0-9_-]*$';


	/**
	 * @ORM\Id
	 * @ORM\Column(type="uuid")
	 * @var \Ramsey\Uuid\UuidInterface
	 */
	private $id;


	/**
	 * @ORM\Column(type="integer", nullable=true, options={"default":0})
	 * @var integer
	 */
	private $type = 0;


	/**
	 * @ORM\Column(type="integer", nullable=true, options={"default":0})
	 * @var integer
	 */
	private $priority = 0;


	/**
	 * @ORM\Column(type="string", length=150, nullable=false, unique=true)
	 * @var string
	 */
	private $urlTitle;


	/**
	 * @ORM\OneToMany(targetEntity="\WorkMarket\App\Model\Faq\CategoryTranslation", mappedBy="category", fetch="EXTRA_LAZY")
	 * @var \Doctrine\Common\Collections\ArrayCollection
	 */
	private $translations;


	/**
	 * @param int $type
	 * @param int $priority
	 * @param string $urlTitle
	 */
	public function __construct(int $type, int $priority, string $urlTitle)
	{
		$this->id = Uuid::uuid4();
		$this->translations = new ArrayCollection;
		$this->type = $type;
		$this->priority = $priority;
		$this->urlTitle = mb_strtolower($urlTitle);
	}


	/**
	 * @return string
	 */
	public function getId()
	{
		return $this->id->toString();
	}


	/**
	 * @return \WorkMarket\App\Model\Faq\CategoryTranslation[]
	 */
	public function getTranslations(): array
	{
		return $this->translations->toArray();
	}


	/**
	 * @param \WorkMarket\App\Model\Languages\Language $language
	 * @return \WorkMarket\App\Model\Faq\CategoryTranslation|null
	 */
	public function getTranslation(Language $language): ?CategoryTranslation
	{
		$criteria = Criteria::create()
			->where(Criteria::expr()->eq('language', $language));

		$translations = $this->translations->matching($criteria);

		return $translations->count() ? $translations->first() : null;
	}


	/**
	 * @param \WorkMarket\App\Model\Languages\Language $language
	 * @return string|null
	 */
	public function getTitle(Language $language): ?string
	{
		$translation = $this->getTranslation($language);

		return $translation ? $translation->getTitle() : null;
	}


	/**
	 * @return array
	 */
	public function getTranslationsList(): array
	{
		$result = [];
		foreach ($this->getTranslations() as $translation) {
			$result[$translation->getLanguage()->getCode()] = $translation->getTitle();
		}

		return $result;
	}


	/**
	 * @param \WorkMarket\App\Model\Faq\CategoryTranslation $translation
	 */
	public function attachTranslation(CategoryTranslation $translation): void
	{
		if ($translation->getCategory() !== $this) {
			throw new InvalidArgumentException('Can not attach translation '. $translation->getId(). ' to entity '. $this->getId(). '.');
		}

		$this->translations->add($translation);
	}


	/**
	 * @param \WorkMarket\App\Model\Faq\CategoryTranslation $translation
	 */
	public function detachTranslation(CategoryTranslation $translation): void
	{
		$this->translations->removeElement($translation);
	}


	/**
	 * @return int
	 */
	public function getPriority(): int
	{
		return $this->priority;
	}


	/**
	 * @param int $priority
	 */
	public function setPriority(int $priority): void
	{
		$this->priority = $priority;
	}


	/**
	 * @return int
	 */
	public function getType(): int
	{
		return $this->type;
	}


	/**
	 * @param int $type
	 */
	public function setType(int $type): void
	{
		$this->type = $type;
	}


	/**
	 * @return string
	 */
	public function getUrlTitle(): string
	{
		return $this->urlTitle;
	}


	/**
	 * @param string $urlTitle
	 */
	public function setUrlTitle(string $urlTitle): void
	{
		$this->urlTitle = mb_strtolower($urlTitle);
	}

}
