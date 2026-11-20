import { defineWireModuleConfig } from '../../../vite.module.ts';

export default defineWireModuleConfig(import.meta.dirname, {
	jsx: 'automatic',
	tailwind: true,
});
