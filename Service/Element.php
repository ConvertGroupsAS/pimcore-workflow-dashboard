<?php
/**
 * @date        22/01/2018
 * @author      Korneliusz Kirsz <kkirsz@divante.pl>
 * @copyright   Copyright (c) 2018 DIVANTE (http://divante.pl)
 */

declare(strict_types=1);

namespace Divante\WorkflowBoardBundle\Service;

use Divante\WorkflowBoardBundle\Model;
use Divante\WorkflowBoardBundle\DivanteWorkflowBoardBundle;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Element\WorkflowState;
use Pimcore\Model\Element\Service as ElementService;
use Pimcore\Model\Notification\Service\NotificationService; 
use Pimcore\Model\User;
use Pimcore\Tool\Admin;

/**
 * Class Element
 * @SuppressWarnings(PHPMD)
 * @package Divante\WorkflowBoardBundle\Service
 */
class Element
{
    const WORKFLOW_CHANGE_ASSIGN_USER_TITLE    = 'Workflow: Element %s has been assigned to you';
    const WORKFLOW_CHANGE_ASSIGN_USER_MESSAGE  = 'Workflow: Element %s has been assigned to you';
    const WORKFLOW_CHANGE_ASSIGN_GROUP_TITLE   = 'Workflow: Element %s has been assigned to your group';
    const WORKFLOW_CHANGE_ASSIGN_GROUP_MESSAGE = 'Workflow: Element %s has been assigned to your group';

    /**
     * @var NotificationService
     */
    protected $notificationService;

    /**
     * Element constructor.
     * @param NotificationService $service
     */
    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * @param int $workflowId
     * @param int $userId
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function findAll(int $workflowId, int $userId, int $offset, int $limit) : array
    {
        $listing = new Model\Element\Listing();
        $listing->setOffset($offset);
        $listing->setLimit($limit);

        $this->decorateCondition($workflowId, $userId, $listing);
        $listing->load();

        return [
            'total' => $listing->getTotalCount(),
            'data'  => $listing->getElements(),
        ];
    }

    /**
     * @param int $workflowId
     * @param int $userId
     * @param Model\Element\Listing $listing
     */
    protected function decorateCondition(int $workflowId, int $userId, Model\Element\Listing $listing)
    {
        $condition = 'workflowId = ?';
        $value = [$workflowId];

        if (-1 === $userId) {
            $user       = Admin::getCurrentUser();
            $permission = DivanteWorkflowBoardBundle::PERMISSION_WORKFLOW_BOARD_ADMIN;
            if (!$user->isAdmin() && !$user->isAllowed($permission)) {
                $userId = (int) $user->getId();
            }
        }

        if ($userId !== -1) {
            $key = '(assignType = ? AND assignId = ?)';
            $value[] = Model\Element::ASSIGN_TYPE_USER;
            $value[] = $userId;

            $roleIds = $this->getUser($userId)->getRoles();
            $len = count($roleIds);

            if ($len > 0) {
                $key = '(' . $key . ' OR (assignType = ? AND assignId IN (%s)))';
                $key = sprintf($key, implode(', ', array_fill(0, $len, '?')));

                $value[] = Model\Element::ASSIGN_TYPE_ROLE;
                $value = array_merge($value, $roleIds);
            }

            $condition .= ' AND ' . $key;
        }

        $listing->setCondition($condition, $value);
    }

    /**
     * @param int $id
     * @return User
     */
    protected function getUser(int $id) : User
    {
        $user = User::getById($id);
        if (!$user instanceof User) {
            $message = "No user found with ID " . $id;
            throw new \UnexpectedValueException($message);
        }
        return $user;
    }

    /**
     * @param int $cid
     * @param string $ctype
     * @param int $workflowId
     * @param string $assignType
     * @param int $assignId
     */
    public function changeAssign(int $cid, string $ctype, int $workflowId, string $assignType, int $assignId)
    {
        $this->beginTransaction();

        $workflowElement = Model\Element::getByPrimary($cid, $ctype, $workflowId);
        if (!$workflowElement instanceof Model\Element) {
            $message = "No workflow dashboard element found with primary key (%d, %s, %d)";
            $message = sprintf($message, $cid, $ctype, $workflowId);
            throw new \UnexpectedValueException($message);
        }

        $workflowElement->setAssignType($assignType);
        $workflowElement->setAssignId($assignId);
        $workflowElement->save();

        $element = $this->getElement($ctype, (string) $cid);
        if (!$element instanceof ElementInterface) {
            throw new \UnexpectedValueException("No element found");
        }

        if (Model\Element::ASSIGN_TYPE_USER == $assignType) {
            $title = sprintf(static::WORKFLOW_CHANGE_ASSIGN_USER_TITLE, $element->getKey());
            $message = sprintf(static::WORKFLOW_CHANGE_ASSIGN_USER_MESSAGE, $element->getFullPath());
            $this->sendNotificationToUser($title, $message, $assignId, $element);
        } elseif (Model\Element::ASSIGN_TYPE_ROLE == $assignType) {
            $title = sprintf(static::WORKFLOW_CHANGE_ASSIGN_GROUP_TITLE, $element->getKey());
            $message = sprintf(static::WORKFLOW_CHANGE_ASSIGN_GROUP_MESSAGE, $element->getFullPath());
            $this->sendNotificationToRole($title, $message, $assignId, $element);
        }

        $this->commit();
    }

    /**
     * @param string $title
     * @param string $message
     * @param int $role
     * @param ElementInterface $element
     */
    protected function sendNotificationToRole(string $title, string $message, int $role, ElementInterface $element)
    {
        $fromUser = (int) Admin::getCurrentUser()->getId();
        $this->notificationService->sendToGroup(
            $role,
            $fromUser,
            $title,
            $message, 
            $element
        );
    }

    /**
     * @param string $title
     * @param string $message
     * @param int $user
     * @param ElementInterface $element
     */
    protected function sendNotificationToUser(string $title, string $message, int $user, ElementInterface $element)
    {
        $fromUser = (int) Admin::getCurrentUser()->getId();
        $this->notificationService->sendToUser(
            $user,
            $fromUser,
            $title,
            $message,
            $element
        );
    }

    /**
     * Synchronizes workflow dashboard elements with workflow states
     */
    public function synchronizeAll()
    {
        $this->beginTransaction();

        // remove unused
        $listing = new Model\Element\Listing();
        $listing->load();

        foreach ($listing->getElements() as $workflowElement) {
            $cid        = $workflowElement->getCid();
            $ctype      = $workflowElement->getCtype();
            $workflowId = $workflowElement->getWorkflowId();

            $workflowState = WorkflowState::getByPrimary($cid, $ctype, $workflowId);
            $element = $this->getElement($ctype, $cid);
            if (!$workflowState instanceof WorkflowState || !$element instanceof ElementInterface) {
                $workflowElement->delete();
            }
        }

        // add or edit
        $listing = new WorkflowState\Listing();
        $listing->load();

        foreach ($listing->getWorkflowStates() as $workflowState) {
            $cid        = $workflowState->getCid();
            $ctype      = $workflowState->getCtype();
            $workflowId = $workflowState->getWorkflowId();

            $element = $this->getElement($ctype, $cid);
            if ($element instanceof ElementInterface) {
                $workflowElement = Model\Element::getByPrimary($cid, $ctype, $workflowId);

                if (!$workflowElement instanceof Model\Element) {
                    $workflowElement = new Model\Element();
                    $workflowElement->setCid($cid);
                    $workflowElement->setCtype($ctype);
                    $workflowElement->setWorkflowId($workflowId);
                    $workflowElement->setAssignId($element->getUserOwner() ?? 0);
                }

                $workflowElement->setState($workflowState->getState());
                $workflowElement->setStatus($workflowState->getStatus());
                $workflowElement->save();
            }
        }

        $this->commit();
    }

    /**
     * @param string $ctype
     * @param string $cid
     * @return ElementInterface|null
     */
    protected function getElement(string $ctype, string $cid)
    {
        return ElementService::getElementById($ctype, $cid);
    }

    /**
     *
     */
    protected function beginTransaction()
    {
        \Pimcore\Db::getConnection()->beginTransaction();
    }

    /**
     *
     */
    protected function commit()
    {
        \Pimcore\Db::getConnection()->commit();
    }
}
