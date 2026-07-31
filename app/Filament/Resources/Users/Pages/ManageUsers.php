<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Tables\Table;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add account'),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    // Deleting the only account locks everyone out of the
                    // panel permanently -- the sole way back in is to create
                    // one over SSH.
                    ->hidden(fn (User $record) => User::count() <= 1)
                    ->modalDescription(fn (User $record) => $record->is(auth()->user())
                        ? 'This is the account you are signed in with. Deleting it signs you out immediately.'
                        : null),
            ]);
    }
}
