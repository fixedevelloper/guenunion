<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait HasUuid
{
    /**
     * Boot du trait HasUuid.
     * Laravel appelle automatiquement cette méthode au chargement du modèle qui l'utilise.
     */
    protected static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            // Déterminer dynamiquement le nom de la colonne UUID (par défaut 'uuid')
            $uuidColumn = $model->getUuidColumnName();

            // Si la colonne est vide, on génère un UUID de type 4 de manière sécurisée
            if (empty($model->{$uuidColumn})) {
                $model->{$uuidColumn} = (string) Str::uuid();
            }
        });
    }

    /**
     * Obtenir le nom de la colonne contenant l'UUID.
     * Peut être surchargé dans un modèle spécifique si la colonne ne s'appelle pas 'uuid'.
     */
    public function getUuidColumnName(): string
    {
        return property_exists($this, 'uuidColumn') ? $this->uuidColumn : 'uuid';
    }

    /**
     * Portée de recherche locale (Scope) pour retrouver facilement un enregistrement par son UUID.
     * Utilisation : Client::findByUuid($uuid)->first();
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $uuid
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWhereUuid($query, string $uuid)
    {
        return $query->where($this->getUuidColumnName(), $uuid);
    }
}
