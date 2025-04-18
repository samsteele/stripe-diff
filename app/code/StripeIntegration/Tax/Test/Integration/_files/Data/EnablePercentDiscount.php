<?php

$objectManager = \Magento\TestFramework\ObjectManager::getInstance();
$searchCriteriaBuilder = $objectManager->get(\Magento\Framework\Api\SearchCriteriaBuilder::class);
$ruleRepository = $objectManager->get(\Magento\SalesRule\Api\RuleRepositoryInterface::class);

$searchCriteriaBuilder->addFilter('name', '10% discount per item');
$rules = $ruleRepository->getList($searchCriteriaBuilder->create())->getItems();
$rule = array_pop($rules);
$rule->setIsActive(1);
$ruleRepository->save($rule);