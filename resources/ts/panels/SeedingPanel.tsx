import type * as React from 'react';
import { DynamicIcon, useState } from '@pw/react/core';
import {
	Button,
	Card,
	CardContent,
	CardDescription,
	CardHeader,
	CardTitle,
	CliBox,
	EmptyState,
	Input,
	Label,
	Select,
	StatCard,
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from '@pw/react/ui';
import { useTranslations } from '@pw/react/hooks';
import type { ActionHelperProps, SeederItem, SeedingPayload } from '../types';

interface SeedingPanelProps extends ActionHelperProps {
	seeding: SeedingPayload | null;
}

export function SeedingPanel({
	seeding,
	isSuperuser,
	busyAction,
	openConfirm,
	inspectFile,
}: SeedingPanelProps) {
	const { t } = useTranslations('ProcessWireConsole');
	const [selectedSeeder, setSelectedSeeder] = useState('all');
	const [newSeederName, setNewSeederName] = useState('');

	if (!seeding) {
		return (
			<EmptyState
				icon="plant"
				title={t('seeding.unavailableTitle', 'Seeding Section Unavailable')}
				description={t('seeding.unavailableDescription', 'You do not have permission to view database seeders.')}
			/>
		);
	}

	const seeders: SeederItem[] = Array.isArray(seeding.seeders) ? seeding.seeders : [];
	const seederOptions = [
		{ value: 'all', label: t('seeding.allSeedersOption', 'All Seeders (or DatabaseSeeder)') },
		...seeders.map((s) => ({ value: s.name, label: `${s.name} (${s.class})` })),
	];

	return (
		<div className="space-y-6">
			<div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
				<StatCard
					label={t('seeding.discoveredSeeders', 'Discovered Seeders')}
					value={seeders.length}
					hint={t('seeding.classesInSeedersHint', 'Classes in site/seeders/')}
					icon="plant"
				/>
				<StatCard
					label={t('seeding.fakerGenerator', 'FakerPHP Generator')}
					value={seeding.hasFaker ? t('migrations.installed', 'Installed') : t('migrations.notInstalled', 'Not Installed')}
					hint={t('seeding.fakerFactoryHint', 'Faker\\Factory availability')}
					icon="sparkles"
					tone={seeding.hasFaker ? 'success' : 'warning'}
				/>
				<StatCard
					label={t('seeding.defaultEntrypoint', 'Default Entrypoint')}
					value={seeders.some((s) => s.name === 'DatabaseSeeder') ? 'DatabaseSeeder' : t('seeding.allSeedersShort', 'All Seeders')}
					hint="php vendor/bin/wire db:seed"
					icon="database"
				/>
			</div>

			<div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
				<Card>
					<CardHeader className="pb-3">
						<div className="flex items-center gap-2">
							<DynamicIcon name="plant" className="h-4 w-4 text-primary" />
							<CardTitle>{t('seeding.executeTitle', 'Execute Database Seeders')}</CardTitle>
						</div>
						<CardDescription>{t('seeding.executeDescription', 'Run a specific seeder class or execute all discovered seeders.')}</CardDescription>
					</CardHeader>
					<CardContent className="space-y-4">
						<div className="space-y-1.5">
							<Label htmlFor="seeder-class">{t('seeding.targetSeederLabel', 'Target Seeder')}</Label>
							<Select
								id="seeder-class"
								value={selectedSeeder}
								onChange={(e: React.ChangeEvent<HTMLSelectElement>) => setSelectedSeeder(e.target.value)}
								options={seederOptions}
							/>
						</div>
						<CliBox
							wrap
							command={
								selectedSeeder === 'all'
									? 'php vendor/bin/wire db:seed --force'
									: `php vendor/bin/wire db:seed --class=${selectedSeeder} --force`
							}
							label={t('workspace.cliEquivalent', 'CLI Equivalent')}
						/>
						<div className="flex justify-end">
							<Button
								icon="player-play"
								disabled={!isSuperuser || busyAction !== null || seeders.length === 0}
								onClick={() =>
									openConfirm({
										action: 'runSeeders',
										guardKey: 'seeding_run',
										confirmWord: 'SEED',
										variant: 'warning',
										title: t('seeding.runSeedersConfirmTitle', 'Run Database Seeders'),
										description: t(
											'seeding.runSeedersConfirmDesc',
											{ target: selectedSeeder },
											'Execute seeder target "{target}" against the active database.'
										),
										payload: { seeder_class: selectedSeeder },
									})
								}
							>
								{t('seeding.runSeeder', 'Run Seeder')}
							</Button>
						</div>
					</CardContent>
				</Card>

				<Card>
					<CardHeader className="pb-3">
						<div className="flex items-center gap-2">
							<DynamicIcon name="plus" className="h-4 w-4 text-primary" />
							<CardTitle>{t('seeding.scaffoldTitle', 'Scaffold Seeder Class')}</CardTitle>
						</div>
						<CardDescription>{t('seeding.scaffoldDesc', 'Create a new seeder class in site/seeders/.')}</CardDescription>
					</CardHeader>
					<CardContent className="space-y-4">
						<div className="space-y-1.5">
							<Label htmlFor="make-seeder-name">{t('seeding.seederClassNameLabel', 'Seeder Class Name')}</Label>
							<Input
								id="make-seeder-name"
								placeholder={t('seeding.seederPlaceholder', 'e.g. ProductCatalogSeeder')}
								value={newSeederName}
								onChange={(e: React.ChangeEvent<HTMLInputElement>) => setNewSeederName(e.target.value)}
							/>
						</div>
						<CliBox
							wrap
							command={`php vendor/bin/wire make:seeder ${newSeederName || 'ProductCatalogSeeder'}`}
							label={t('workspace.cliEquivalent', 'CLI Equivalent')}
						/>
						<div className="flex justify-end">
							<Button
								variant="secondary"
								icon="plus"
								disabled={!isSuperuser || busyAction !== null || newSeederName.trim() === ''}
								onClick={() =>
									openConfirm({
										action: 'makeSeederClass',
										guardKey: 'seeding_make',
										confirmWord: 'CREATE',
										variant: 'primary',
										title: t('seeding.scaffoldTitle', 'Scaffold Seeder Class'),
										description: t(
											'seeding.scaffoldConfirmDesc',
											{ name: newSeederName.trim() },
											'Create seeder "{name}" in site/seeders/.'
										),
										payload: { make_seeder_name: newSeederName.trim() },
									})
								}
							>
								{t('seeding.createSeeder', 'Create Seeder')}
							</Button>
						</div>
					</CardContent>
				</Card>
			</div>

			<Card>
				<CardHeader className="pb-3">
					<CardTitle>{t('seeding.discoveredSeedersCount', { count: seeders.length }, 'Discovered Seeders ({count})')}</CardTitle>
					<CardDescription>{t('seeding.registeredInSeedersDesc', 'Seeder classes registered in site/seeders/')}</CardDescription>
				</CardHeader>
				<CardContent>
					{seeders.length === 0 ? (
						<EmptyState
							icon="plant"
							title={t('seeding.emptyTitle', 'No Database Seeders Found')}
							description={t('seeding.emptyDescription', 'Scaffold a Seeder class above to populate test or initial data.')}
						/>
					) : (
						<Table>
							<TableHeader>
								<TableRow>
									<TableHead>{t('seeding.seederNameHead', 'Seeder Name')}</TableHead>
									<TableHead>{t('seeding.fqcnHead', 'FQCN')}</TableHead>
									<TableHead className="text-right">{t('workspace.actionsHead', 'Actions')}</TableHead>
								</TableRow>
							</TableHeader>
							<TableBody>
								{seeders.map((seeder) => (
									<TableRow key={seeder.name}>
										<TableCell className="font-mono text-xs font-medium">{seeder.name}</TableCell>
										<TableCell className="font-mono text-xs text-muted-foreground">{seeder.class}</TableCell>
										<TableCell className="text-right">
											<div className="inline-flex items-center gap-1">
												<Button
													variant="ghost"
													size="sm"
													icon="eye"
													onClick={() => inspectFile('seeder', seeder.name)}
												>
													{t('workspace.source', 'Source')}
												</Button>
												<Button
													variant="outline"
													size="sm"
													icon="player-play"
													disabled={!isSuperuser || busyAction !== null}
													onClick={() =>
														openConfirm({
															action: 'runSeeders',
															guardKey: 'seeding_run',
															confirmWord: 'SEED',
															variant: 'warning',
															title: t('seeding.runOneConfirmTitle', { name: seeder.name }, 'Run {name}'),
															description: t('seeding.runOneConfirmDesc', { name: seeder.name }, 'Execute seeder "{name}" now.'),
															payload: { seeder_class: seeder.name },
														})
													}
												>
													{t('workspace.run', 'Run')}
												</Button>
											</div>
										</TableCell>
									</TableRow>
								))}
							</TableBody>
						</Table>
					)}
				</CardContent>
			</Card>
		</div>
	);
}
