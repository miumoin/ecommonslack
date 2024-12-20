<?php

namespace App\Entity;

use App\Repository\MetasRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: MetasRepository::class)]
#[UniqueEntity(fields: ['parent', 'parent_id', 'meta_key'], message: 'User already exists.')]
#[ORM\Table(name: 'metas', uniqueConstraints: [
    new ORM\UniqueConstraint(name: 'unique_meta_constraint', columns: ['parent', 'parent_id', 'meta_key'])
])]
class Metas
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 35)]
    private ?string $parent = null;

    #[ORM\Column]
    private ?int $parent_id = null;

    #[ORM\Column(length: 120)]
    private ?string $meta_key = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $meta_value = null;

    #[ORM\Column]
    private ?int $status = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getParent(): ?string
    {
        return $this->parent;
    }

    public function setParent(string $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    public function getParentId(): ?int
    {
        return $this->parent_id;
    }

    public function setParentId(int $parent_id): static
    {
        $this->parent_id = $parent_id;

        return $this;
    }

    public function getMetaKey(): ?string
    {
        return $this->meta_key;
    }

    public function setMetaKey(string $meta_key): static
    {
        $this->meta_key = $meta_key;

        return $this;
    }

    public function getMetaValue(): ?string
    {
        return $this->meta_value;
    }

    public function setMetaValue(string $meta_value): static
    {
        $this->meta_value = $meta_value;

        return $this;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function setStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }
}
