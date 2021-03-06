<?php

/* For licensing terms, see /license.txt */

namespace Chamilo\CourseBundle\Repository;

use APY\DataGridBundle\Grid\Column\Column;
use APY\DataGridBundle\Grid\Grid;
use Chamilo\CoreBundle\Component\Resource\Settings;
use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\ResourceLink;
use Chamilo\CoreBundle\Entity\ResourceNode;
use Chamilo\CoreBundle\Entity\Session;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\CoreBundle\Form\Resource\CDocumentType;
use Chamilo\CoreBundle\Repository\GridInterface;
use Chamilo\CoreBundle\Repository\ResourceRepository;
use Chamilo\CoreBundle\Repository\UploadInterface;
use Chamilo\CourseBundle\Entity\CDocument;
use Chamilo\CourseBundle\Entity\CGroupInfo;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Class CDocumentRepository.
 */
final class CDocumentRepository extends ResourceRepository implements GridInterface, UploadInterface
{
    public function getResources(User $user, ResourceNode $parentNode, Course $course = null, Session $session = null, CGroupInfo $group = null): QueryBuilder
    {
        return $this->getResourcesByCourse($course, $session, $group, $parentNode);
    }

    public function getResourceSettings(): Settings
    {
        $settings = parent::getResourceSettings();

        $settings
            ->setAllowNodeCreation(true)
            ->setAllowResourceCreation(true)
            ->setAllowResourceUpload(true)
            ->setAllowDownloadAll(true)
            ->setAllowDiskSpace(true)
            ->setAllowToSaveEditorToResourceFile(true)
        ;

        return $settings;
    }

    public function saveUpload(UploadedFile $file)
    {
        $resource = new CDocument();
        $resource
            ->setFiletype('file')
            ->setSize($file->getSize())
            ->setTitle($file->getClientOriginalName())
        ;

        return $resource;
    }

    public function setResourceProperties(FormInterface $form, $course, $session, $fileType)
    {
        $newResource = $form->getData();
        $newResource
            ->setCourse($course)
            ->setSession($session)
            ->setFiletype($fileType)
            //->setTitle($title) // already added in $form->getData()
            ->setReadonly(false)
        ;

        return $newResource;
    }

    /**
     * @return string
     */
    public function getDocumentUrl(CDocument $document)
    {
        // There are no URL for folders.
        if ('folder' === $document->getFiletype()) {
            return '';
        }
        $file = $document->getResourceNode()->getResourceFile();

        if (null === $file) {
            return '';
        }

        $params = [
            'course' => $document->getCourse()->getCode(),
            'id' => ltrim($document->getPath(), '/'),
            'tool' => 'document',
            'type' => $document->getResourceNode()->getResourceType()->getName(),
        ];

        return $this->getRouter()->generate(
            'chamilo_core_resource_view_file',
            $params
        );
    }

    /**
     * @return CDocument|null
     */
    public function getParent(CDocument $document)
    {
        $resourceParent = $document->getResourceNode()->getParent();

        if (null !== $resourceParent) {
            $resourceParentId = $resourceParent->getId();
            $criteria = [
                'resourceNode' => $resourceParentId,
            ];

            return $this->findOneBy($criteria);
        }

        return null;
    }

    /**
     * @param int    $courseId
     * @param string $path
     *
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function getFolderSize($courseId, $path)
    {
        $path = str_replace('_', '\_', $path);
        $addedSlash = '/' === $path ? '' : '/';

        $repo = $this->getRepository();
        $qb = $repo->createQueryBuilder('d');
        $query = $qb
            ->select('SUM(d.size)')
            ->innerJoin('d.resourceNode', 'r')
            ->innerJoin('r.resourceLinks', 'l')
            ->where('d.path LIKE :path')
            ->andWhere('d.path NOT LIKE :deleted')
            ->andWhere('d.path NOT LIKE :extra_path ')
            ->andWhere('l.visibility <> :visibility')
            ->andWhere('d.course = :course')
            ->setParameters([
                'path' => $path.$addedSlash.'%',
                'extra_path' => $path.$addedSlash.'%/%',
                'course' => $courseId,
                'deleted' => '%_DELETED_%',
                'visibility' => ResourceLink::VISIBILITY_DELETED,
            ])
            ->getQuery();

        return $query->getSingleScalarResult();
    }

    /**
     * @param int $courseId
     * @param int $groupId
     * @param int $sessionId
     *
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function getTotalSpace($courseId, $groupId = null, $sessionId = null)
    {
        $repo = $this->getRepository();
        $groupId = empty($groupId) ? null : $groupId;
        $sessionId = empty($sessionId) ? null : $sessionId;

        $qb = $repo->createQueryBuilder('d');
        $query = $qb
            ->select('SUM(d.size)')
            ->innerJoin('d.resourceNode', 'r')
            ->innerJoin('r.resourceLinks', 'l')
            ->where('l.course = :course')
            ->andWhere('l.group = :group')
            ->andWhere('l.session = :session')
            ->andWhere('l.visibility <> :visibility')
            ->setParameters([
                'course' => $courseId,
                'group' => $groupId,
                'session' => $sessionId,
                'visibility' => ResourceLink::VISIBILITY_DELETED,
            ])
            ->getQuery();

        return $query->getSingleScalarResult();
    }

    /**
     * @param int $userId
     *
     * @return array
     */
    public function getAllDocumentsByAuthor($userId)
    {
        $repo = $this->repository;

        $qb = $repo->createQueryBuilder('d');
        $query = $qb
            ->innerJoin('d.resourceNode', 'r')
            ->innerJoin('r.resourceLinks', 'l')
            ->where('l.user = :user')
            ->andWhere('l.visibility <> :visibility')
            ->setParameters([
                'user' => $userId,
                'visibility' => ResourceLink::VISIBILITY_DELETED,
            ])
            ->getQuery();

        return $query->getResult();
    }

    public function getTitleColumn(Grid $grid): Column
    {
        return $grid->getColumn('title');
    }

    public function getResourceFormType(): string
    {
        return CDocumentType::class;
    }
}
