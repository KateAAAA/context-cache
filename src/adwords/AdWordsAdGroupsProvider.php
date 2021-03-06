<?php

namespace sitkoru\contextcache\adwords;

use Google\AdsApi\AdWords\AdWordsSession;
use Google\AdsApi\AdWords\v201708\cm\AdGroup;
use Google\AdsApi\AdWords\v201708\cm\AdGroupOperation;
use Google\AdsApi\AdWords\v201708\cm\AdGroupService;
use Google\AdsApi\AdWords\v201708\cm\Operand;
use Google\AdsApi\AdWords\v201708\cm\Operator;
use Google\AdsApi\AdWords\v201708\cm\Predicate;
use Google\AdsApi\AdWords\v201708\cm\PredicateOperator;
use Google\AdsApi\AdWords\v201708\cm\Selector;
use Psr\Log\LoggerInterface;
use sitkoru\contextcache\common\ICacheProvider;
use sitkoru\contextcache\common\IEntitiesProvider;
use sitkoru\contextcache\common\models\UpdateResult;
use sitkoru\contextcache\helpers\ArrayHelper;

class AdWordsAdGroupsProvider extends AdWordsEntitiesProvider implements IEntitiesProvider
{
    /**
     * @var AdGroupService
     */
    private $adGroupService;

    private static $fields = [
        'AdGroupType',
        'BaseAdGroupId',
        'BaseCampaignId',
        'BidType',
        'BiddingStrategyId',
        'BiddingStrategyName',
        'BiddingStrategySource',
        'BiddingStrategyType',
        'CampaignId',
        'CampaignName',
        'ContentBidCriterionTypeGroup',
        'CpcBid',
        'CpmBid',
        'EnhancedCpcEnabled',
        'Id',
        'Labels',
        'Name',
        'Settings',
        'Status',
        'TargetCpa',
        'TargetCpaBid',
        'TargetCpaBidSource',
        'TargetSpendEnhancedCpcEnabled',
        'TrackingUrlTemplate',
        'UrlCustomParameters'
    ];

    public function __construct(
        AdGroupService $adGroupService,
        ICacheProvider $cacheProvider,
        AdWordsSession $adWordsSession,
        LoggerInterface $logger
    ) {
        parent::__construct($cacheProvider, $adWordsSession, $logger);
        $this->collection = 'adGroups';
        $this->adGroupService = $adGroupService;
    }

    /**
     * @param array $ids
     * @return AdGroup[]
     * @throws \Exception
     */
    public function getAll(array $ids): array
    {
        $notFound = $ids;
        /**
         * @var AdGroup[] $adGroups
         */
        $adGroups = $this->getFromCache($ids, 'id');
        if ($adGroups) {
            $found = array_keys($adGroups);
            $notFound = array_values(array_diff($ids, $found));
        }
        if ($notFound) {
            $selector = new Selector();
            $selector->setFields(self::$fields);
            $predicates[] = new Predicate('Id', PredicateOperator::IN, $ids);
            $selector->setPredicates($predicates);
            $fromService = (array)$this->adGroupService->get($selector)->getEntries();
            foreach ($fromService as $adGroupItem) {
                $adGroups[$adGroupItem->getId()] = $adGroupItem;
            }
            $this->addToCache($fromService);
        }
        return $adGroups;
    }

    /**
     * @param $id
     * @return AdGroup|null
     * @throws \Exception
     */
    public function getOne($id): AdGroup
    {
        $adGroups = $this->getAll([$id]);
        if ($adGroups) {
            return reset($adGroups);
        }
        return null;
    }

    /**
     * @param array $campaignIds
     * @return AdGroup[]
     * @throws \Google\AdsApi\AdWords\v201708\cm\ApiException
     */
    public function getByCampaignIds(array $campaignIds): array
    {
        $notFound = $campaignIds;
        /**
         * @var AdGroup[] $adGroups
         */
        $adGroups = $this->getFromCache($campaignIds, 'campaignId', 'id');
        if ($adGroups) {
            $found = array_unique(ArrayHelper::getColumn($adGroups, 'campaignId'));
            $notFound = array_values(array_diff($campaignIds, $found));
        }
        if ($notFound) {
            $selector = new Selector();
            $selector->setFields(self::$fields);
            $predicates[] = new Predicate('CampaignId', PredicateOperator::IN, $notFound);
            $selector->setPredicates($predicates);
            $fromService = (array)$this->adGroupService->get($selector)->getEntries();
            foreach ($fromService as $adGroupItem) {
                $adGroups[$adGroupItem->getId()] = $adGroupItem;
            }
            $this->addToCache($fromService);
        }
        return $adGroups;
    }

    /**
     * @param AdGroup[] $entities
     * @return UpdateResult
     * @throws \Exception
     */
    public function update(array $entities): UpdateResult
    {
        $result = new UpdateResult();
        $addOperations = [];
        $this->logger->info('Build operations');
        foreach ($entities as $entity) {
            $addOperation = new AdGroupOperation();
            if($entity->getBiddingStrategyConfiguration()!==null)
            {
                $entity->setBiddingStrategyConfiguration(null);
            }
            $addOperation->setOperand($entity);
            $addOperation->setOperator(Operator::SET);
            $addOperations[] = $addOperation;
        }
        $this->logger->info('Update operations: ' . count($addOperations));

        foreach (array_chunk($addOperations, self::MAX_OPERATIONS_SIZE) as $i => $addChunk) {
            $this->logger->info('Update chunk #' . $i . '. Size: ' . count($addChunk));
            $jobResults = $this->runMutateJob($addChunk);
            $this->processJobResult($result, $jobResults);
        }
        $this->logger->info('Done');
        $this->clearCache();
        return $result;
    }

    protected function getOperandEntity(Operand $operand)
    {
        return $operand->getAdGroup();
    }
}