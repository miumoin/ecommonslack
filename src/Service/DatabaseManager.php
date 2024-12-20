<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Systems;
use App\Entity\Users;
use App\Entity\Blocks;
use App\Entity\Metas;

class DatabaseManager
{
    private $em;
    private $entityManager;
    private $domain;
    private $accessKey;
    private $userId;
    private $systemId;

    public function __construct(EntityManagerInterface $entityManager, string $domain, string $accessKey)
    {
        $this->entityManager = $entityManager;
        $this->domain = $domain;
        $this->accessKey = $accessKey;

        // Initialize user and system IDs
        $this->userId = $this->getUserIdByAccessKey($accessKey);
        $this->systemId = $this->getSystemIdByDomain($domain);
    }

    public function getCurrentUser(): int {
        return $this->userId;
    }

    private function getUserIdByAccessKey($accessKey): ?int
    {
        $user = $this->entityManager->getRepository(Users::class)->findOneBy([
            'access_key' => $accessKey
        ]);

        if ($user) {
            return $user->getId();  // Or return an array with other information if needed
        }

        return null; // or handle error
    }

    private function getSystemIdByDomain($domain): ?int
    {
        // Check if the system exists by subDomain or domain
        $systems = $this->entityManager->getRepository(Systems::class)
                    ->createQueryBuilder('s')
                    ->where('s.subdomain = :domain OR s.domain = :domain')
                    ->andWhere('s.status = :status')
                    ->setParameter('domain', $domain)
                    ->setParameter('status', 1)
                    ->getQuery()
                    ->getOneOrNullResult();

        // If system exists, return its ID
        if ($systems) {
            return $systems->getId();
        }

        // If system does not exist, create a new system entry
        $newSystems = new Systems();
        $newSystems->setSubDomain($domain);
        $newSystems->setDomain($domain);
        $newSystems->setStatus(1); // Assuming 0 is the default status for new systems

        // Persist the new system and flush to save it in the database
        $this->entityManager->persist($newSystems);
        $this->entityManager->flush();

        // Return the ID of the newly created system
        return $newSystems->getId();
    }

    public function addUser(string $email, string $password): ?int
    {
        $existingUser = $this->entityManager->getRepository(Users::class)->findOneBy(['email' => $email]);
        if ($existingUser) {
            throw new \Exception('User with this email already exists.');
        }

        $user = new Users();

        // Set user data
        $user->setEmail($email);
        $user->setPassword(md5($password));  // Hash password using md5
        $user->setAccessKey(uniqid());     // Generate unique access token
        $user->setSystemId( $this->systemId );

        // Persist the new user and flush to save in the database
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Return the new user's ID
        return $user->getId();
    }

    /*
    ** Special user with email as shopify store and password as plain access token
    */
    public function addShopifyStoreAsUser(string $email, string $password): ?array
    {
        $existingUser = $this->entityManager->getRepository(Users::class)->findOneBy(['email' => $email]);
        if ($existingUser) {
            // Update the password for the existing user
            $existingUser->setPassword($password); // Hash password using md5
            $this->entityManager->flush(); // Save the update to the database

            // Return the updated user's ID
            return array( 'id' => $existingUser->getId(), 'access_key' => $existingUser->getAccessKey() );
        }

        $user = new Users();

        // Set user data
        $user->setEmail($email);
        $user->setPassword($password);  // Hash password using md5
        $user->setAccessKey(uniqid());     // Generate unique access token
        $user->setSystemId( $this->systemId );

        // Persist the new user and flush to save in the database
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Return the new user's ID
        return array( 'id' => $user->getId(), 'access_key' => $user->getAccessKey() );
    }

    public function getShopifyStoreAccessToken( int $user_id ): ?array
    {
        $user = $this->entityManager->getRepository(Users::class)->findOneBy([
            'id' => $user_id
        ]);

        if( $user ) return [ $user->getEmail(), $user->getPassword() ];
        return null;
    }

    public function getShopifyStore( string $shop ): ?array
    {
        $user = $this->entityManager->getRepository(Users::class)->findOneBy([
            'email' => $shop, 'system_id' => $this->systemId
        ]);

        if( $user ) return [ $user->getId(), $user->getAccessKey(), $user->getPassword() ];
        return null;
    }

    public function addMeta(string $parent, int $parentId, string $metaKey, $metaValue): bool
    {
        // If meta value is an array, encode it as JSON
        if (is_array($metaValue)) {
            $metaValue = json_encode($metaValue);
        }

        // Check if the meta entry already exists using the getMeta function
        $existingMeta = $this->getMeta($parent, $parentId, $metaKey);

        if ($existingMeta === null) {
            // Meta entry does not exist, create a new one
            $meta = new Metas();
            $meta->setParent($parent);
            $meta->setParentId($parentId);
            $meta->setMetaKey($metaKey);
            $meta->setMetaValue($metaValue);
            $meta->setStatus(1);

            // Persist the new meta entry
            $this->entityManager->persist($meta);
        } else {
            // Meta entry exists, update the meta_value and set status to 1
            $meta = $this->entityManager->getRepository(Metas::class)->findOneBy([
                'parent' => $parent,
                'parent_id' => $parentId,
                'meta_key' => $metaKey
            ]);

            if ($meta) {
                $meta->setMetaValue($metaValue);
                $meta->setStatus(1);
            }
        }

        // Flush the entity manager to save the changes to the database
        $this->entityManager->flush();

        return true;
    }

    public function getMeta(string $parent, int $parentId, string $key): ?string
    {
        // Find the meta entry using the Doctrine repository
        $meta = $this->entityManager->getRepository(Metas::class)->findOneBy([
            'parent' => $parent,
            'parent_id' => $parentId,
            'meta_key' => $key,
            'status' => 1
        ]);

        // If no meta is found, return null (or false depending on your use case)
        if (!$meta) {
            return null; // Or return false if needed
        }

        // Return the meta value
        return $meta->getMetaValue();
    }

    public function addBlock(int $userId, array $block, string $slug = ''): ?array
    {
        if ($userId <= 0) {
            return false;
        }

        // Generate slug if not provided
        if (empty($slug)) {
            $slug = uniqid();
        }

        // Check if a block with the given slug already exists
        $existingBlock = $this->entityManager->getRepository(Blocks::class)->findOneBy(['slug' => $slug]);

        if (!$existingBlock) {
            // Block does not exist, create a new one
            $newBlock = new Blocks();
            $newBlock->setType($block['type']);
            $newBlock->setTitle($block['title']);
            $newBlock->setContent($block['content']);
            $newBlock->setAuthor($userId);
            $newBlock->setSlug($slug);
            $newBlock->setParent($block['parent'] ?? null);
            $newBlock->setCreatedAt(new \DateTime());
            $newBlock->setModifiedAt(new \DateTime());
            $newBlock->setStatus(1);

            // Persist and save the new block
            $this->entityManager->persist($newBlock);
        } else {
            // Block exists, update the title and content, and modify the updated date
            $existingBlock->setTitle($block['title']);
            $existingBlock->setContent($block['content']);
            $existingBlock->setModifiedAt(new \DateTime());
        }

        // Flush the changes to the database
        $this->entityManager->flush();

        // Fetch the block with the given slug after insertion or update
        $blockData = $this->entityManager->getRepository(Blocks::class)->findOneBy(['slug' => $slug, 'status' => 1]);

        // If the block is found, return its data
        if ($blockData) {
            return [
                'id'          => $blockData->getId(),
                'type'        => $blockData->getType(),
                'title'       => $blockData->getTitle(),
                'content'     => $blockData->getContent(),
                'author'      => $blockData->getAuthor(),
                'slug'        => $blockData->getSlug(),
                'parent'      => $blockData->getParent(),
                'created_at'  => $blockData->getCreatedAt()->format('Y-m-d H:i:s'),
                'modified_at' => $blockData->getModifiedAt()->format('Y-m-d H:i:s')
            ];
        }

        return false;
    }

    public function getBlocks(int $userId, string $type, int $page, int $entriesPerPage, int $parent = 0): ?array
    {
        $offset = ($page - 1) * $entriesPerPage;

        $queryBuilder = $this->entityManager->createQueryBuilder();
        $queryBuilder->select('b')
            ->from(Blocks::class, 'b')
            ->where('b.author = :userId')
            ->andWhere('b.type = :type')
            ->andWhere('b.parent = :parent')
            ->andWhere('b.status = 1')
            ->setParameter('userId', $userId)
            ->setParameter('parent', $parent)
            ->setParameter('type', $type)
            ->orderBy('b.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($entriesPerPage);

        if ($parent > 0) {
            $queryBuilder->andWhere('b.parent = :parent')
                ->setParameter('parent', $parent);
        }

        $blocks = $queryBuilder->getQuery()->getArrayResult();

        return !empty($blocks) ? $blocks : null;
    }

    public function getBlock(int $userId, string $type = '', int $id = 0, string $slug = '', int $parent = 0): ?array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();
        $queryBuilder->select('b')
            ->from(Blocks::class, 'b')
            ->where('b.status > 0')
            ->andWhere('b.author = :userId')
            ->setParameter('userId', $userId);

        if (!empty($type)) {
            $queryBuilder->andWhere('b.type = :type')
                ->setParameter('type', $type);
        }

        if ($id > 0) {
            $queryBuilder->andWhere('b.id = :id')
                ->setParameter('id', $id);
        }

        if (!empty($slug)) {
            $queryBuilder->andWhere('b.slug = :slug')
                ->setParameter('slug', $slug);
        }

        if ($parent > 0) {
            $queryBuilder->andWhere('b.parent = :parent')
                ->setParameter('parent', $parent);
        }

        $block = $queryBuilder->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);

        if ($block) {
            // Fetch children recursively if they exist
            $block['children'] = $this->getBlock($userId, '', 0, '', $block['id']);
        }

        return $block ?: null;
    }

    public function deleteBlock(int $id): void
    {
        $block = $this->entityManager->getRepository(Blocks::class)->find($id);

        if ($block) {
            $block->setStatus(0);  // Set status to inactive (0)
            $this->entityManager->flush();  // Save the change to the database
        }
    }
}