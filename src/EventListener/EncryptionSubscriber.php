<?php

namespace Insitaction\FieldEncryptBundle\EventListener;

use Doctrine\Common\EventSubscriber;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Column;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Exception;
use Insitaction\FieldEncryptBundle\Annotations\Encrypt;
use Insitaction\FieldEncryptBundle\Model\EncryptedFieldsInterface;
use Insitaction\FieldEncryptBundle\Service\EncryptService;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Component\PropertyAccess\PropertyAccess;

class EncryptionSubscriber implements EventSubscriber
{
    public const ENCRYPTION_MARKER = '<ENC>';

    public function __construct(
        private EncryptService $encryptService,
        private EntityManagerInterface $em,
    ) {
    }

    public function getSubscribedEvents(): array
    {
        return [
            Events::preUpdate,
            Events::postUpdate,
            Events::postLoad,
            Events::postFlush,
            Events::preFlush,
            Events::onFlush,
            Events::prePersist,
        ];
    }

    public function preFlush(PreFlushEventArgs $onFlushEventArgs): void
    {
        $unitOfWork = $onFlushEventArgs->getEntityManager()->getUnitOfWork();
        foreach ($unitOfWork->getIdentityMap() as $identityMap) {
            foreach ($identityMap as $entity) {
                if ($entity instanceof EncryptedFieldsInterface) {
                    $this->encryptFields($entity);
                }
            }
        }
    }

    public function onFlush(OnFlushEventArgs $onFlushEventArgs): void
    {
        $unitOfWork = $onFlushEventArgs->getEntityManager()->getUnitOfWork();
        foreach ($unitOfWork->getIdentityMap() as $identityMap) {
            foreach ($identityMap as $entity) {
                if ($entity instanceof EncryptedFieldsInterface) {
                    $this->encryptFields($entity);
                }
            }
        }
    }

    public function postFlush(PostFlushEventArgs $onFlushEventArgs): void
    {
        $unitOfWork = $onFlushEventArgs->getEntityManager()->getUnitOfWork();
        foreach ($unitOfWork->getIdentityMap() as $identityMap) {
            foreach ($identityMap as $entity) {
                if ($entity instanceof EncryptedFieldsInterface) {
                    $this->decryptFields($entity);
                }
            }
        }
    }

    public function prePersist(LifecycleEventArgs $args): void
    {
        $object = $args->getObject();
        if ($object instanceof EncryptedFieldsInterface) {
            $this->encryptFields($object);
        }
    }

    public function preUpdate(LifecycleEventArgs $args): void
    {
        $object = $args->getObject();
        if ($object instanceof EncryptedFieldsInterface) {
            $this->encryptFields($object);
        }
    }

    public function postUpdate(LifecycleEventArgs $args): void
    {
        $object = $args->getObject();
        if ($object instanceof EncryptedFieldsInterface) {
            $this->decryptFields($object);
        }
    }

    public function postLoad(LifecycleEventArgs $args): void
    {
        $object = $args->getObject();

        if ($object instanceof EncryptedFieldsInterface) {
            $this->decryptFields($object);
        }
    }

    private function encryptFields(EncryptedFieldsInterface $entity): void
    {
        $entityClass = ClassUtils::getClass($entity);
        $uow = $this->em->getUnitOfWork();
        $changeset = $uow->getEntityChangeSet($entity);
        $uow->computeChangeSet($this->em->getClassMetadata($entityClass), $entity);

        if (0 !== count($changeset)) {
            foreach ((new ReflectionClass($entityClass))->getProperties() as $reflectionproperty) {
                if (0 !== count($reflectionproperty->getAttributes(Encrypt::class))) {
                    $pac = PropertyAccess::createPropertyAccessor();
                    $value = $pac->getValue($entity, $reflectionproperty->getName());

                    if (null === $value) {
                        /** @var ReflectionAttribute $reflectionAttribute */
                        $reflectionAttribute = $reflectionproperty->getAttributes(Column::class)[0];
                        $arguments = $reflectionAttribute->getArguments();

                        if (isset($arguments['nullable']) && true === $arguments['nullable']) {
                            continue;
                        }

                        throw new Exception('Encrypt error, you must define ' . $reflectionproperty->getName() . ' as nullable.');
                    }

                    if (is_resource($value)) {
                        $value = stream_get_contents($value);

                        if (false === $value) {
                            throw new Exception('Can\'t reads remainder of a stream into a string', 500);
                        }
                    }

                    if (self::ENCRYPTION_MARKER !== substr($value, -strlen(self::ENCRYPTION_MARKER))) {
                        $pac->setValue(
                            $entity,
                            $reflectionproperty->getName(),
                            $this->encryptService->encrypt($value) . self::ENCRYPTION_MARKER
                        );
                    }
                }
            }
        }
    }

    private function decryptFields(EncryptedFieldsInterface $entity): void
    {
        $entityClass = ClassUtils::getClass($entity);

        foreach ((new ReflectionClass($entityClass))->getProperties() as $reflectionproperty) {
            if (0 !== count($reflectionproperty->getAttributes(Encrypt::class))) {
                $pac = PropertyAccess::createPropertyAccessor();
                $value = $pac->getValue($entity, $reflectionproperty->getName());

                if (null === $value) {
                    continue;
                }

                if (is_resource($value)) {
                    $value = stream_get_contents($value);

                    if (false === $value) {
                        throw new Exception('Can\'t reads remainder of a stream into a string', 500);
                    }
                }

                if (self::ENCRYPTION_MARKER === substr($value, -strlen(self::ENCRYPTION_MARKER))) {
                    $pac->setValue(
                        $entity,
                        $reflectionproperty->getName(),
                        $this->encryptService->decrypt(substr($value, 0, -strlen(self::ENCRYPTION_MARKER))));
                }
            }
        }

        $uow = $this->em->getUnitOfWork();
        $origin = $uow->getOriginalEntityData($entity);

        foreach ((new ReflectionClass($entityClass))->getProperties() as $reflectionproperty) {
            if (0 !== count($reflectionproperty->getAttributes(Encrypt::class))) {
                $pac = PropertyAccess::createPropertyAccessor();
                $value = $pac->getValue($entity, $reflectionproperty->getName());
                $origin[$reflectionproperty->getName()] = $value;
            }
        }

        $uow->setOriginalEntityData($entity, $origin);
        $uow->computeChangeSet($this->em->getClassMetadata($entityClass), $entity);
    }
}
