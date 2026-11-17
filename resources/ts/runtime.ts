interface ReactWorkspaceGlobal {
	React: typeof import('react');
	Inertia: typeof import('@inertiajs/react');
	UI: typeof import('../../../ProcessReactWorkspace/resources/ts/ui');
	Layout: {
		AppShell: typeof import('../../../ProcessReactWorkspace/resources/ts/layout/AppShell').AppShell;
	};
	DynamicIcon: typeof import('../../../ProcessReactWorkspace/resources/ts/lib/utils').DynamicIcon;
	registerPages: <TProps>(pages: Record<string, React.ComponentType<TProps>>) => void;
	useTranslations: typeof import('../../../ProcessReactWorkspace/resources/ts/lib/i18n').useTranslations;
	t: typeof import('../../../ProcessReactWorkspace/resources/ts/lib/i18n').t;
	translate: typeof import('../../../ProcessReactWorkspace/resources/ts/lib/i18n').translate;
	registerTranslations: typeof import('../../../ProcessReactWorkspace/resources/ts/lib/i18n').registerTranslations;
	hasTranslation: typeof import('../../../ProcessReactWorkspace/resources/ts/lib/i18n').hasTranslation;
	getLocale: typeof import('../../../ProcessReactWorkspace/resources/ts/lib/i18n').getLocale;
}

const runtime = (window as unknown as { ReactWorkspaceRuntime: ReactWorkspaceGlobal }).ReactWorkspaceRuntime;

export const React = runtime.React;
export const { useState, useEffect } = React;
export const { router } = runtime.Inertia;
export const UI = runtime.UI;
export const {
	Button,
	Badge,
	Card,
	CardHeader,
	CardTitle,
	CardDescription,
	CardContent,
	Label,
	Input,
	Textarea,
	Select,
	Switch,
	Table,
	TableHeader,
	TableBody,
	TableRow,
	TableHead,
	TableCell,
	Alert,
	AlertTitle,
	AlertDescription,
	Sheet,
	StatCard,
	CliBox,
	CodePreview,
	EmptyState,
	ConfirmDialog,
} = UI;
export const { AppShell } = runtime.Layout;
export const DynamicIcon = runtime.DynamicIcon;
export const registerPages = runtime.registerPages;
export const useTranslations = runtime.useTranslations;
export const t = runtime.t;
export const translate = runtime.translate;
export const registerTranslations = runtime.registerTranslations;
export const hasTranslation = runtime.hasTranslation;
export const getLocale = runtime.getLocale;

export type ConfirmDialogVariant = 'primary' | 'warning' | 'danger';

export interface ConfirmDialogConfig {
	action: string;
	guardKey: string;
	confirmWord: string;
	title: string;
	description: string;
	variant?: ConfirmDialogVariant;
	payload?: Record<string, unknown>;
}

export interface ActionHelperProps {
	actionUrl: string;
	activeTab: string;
	isSuperuser: boolean;
	busyAction: string | null;
	runAction: (action: string, payload?: Record<string, unknown>) => void;
	openConfirm: (config: ConfirmDialogConfig) => void;
	inspectFile: (category: string, file: string) => void;
}
