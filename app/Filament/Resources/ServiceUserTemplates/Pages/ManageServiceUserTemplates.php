<?php

namespace App\Filament\Resources\ServiceUserTemplates\Pages;

use App\Filament\Resources\ServiceUserTemplates\ServiceUserTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Tables\Table;

class ManageServiceUserTemplates extends ManageRecords
{
    protected static string $resource = ServiceUserTemplateResource::class;

    public function getSubheading(): ?string
    {
        return __('Reusable profiles applied when adding a user. Changing one never touches users already created from it.');
    }

    // Create/Edit/Delete are policy-gated by Filament, so an Auditor sees the
    // table but none of these buttons and a crafted request is refused.
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('Add template'))
                ->mutateFormDataUsing(ServiceUserTemplateResource::normaliseTemplateData(...)),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(ServiceUserTemplateResource::hydrateTemplateData(...))
                    ->mutateFormDataUsing(ServiceUserTemplateResource::normaliseTemplateData(...)),
                DeleteAction::make(),
            ]);
    }
}
