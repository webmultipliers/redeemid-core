import { defineConfig } from 'vite';
import path from 'path';
import viteSassGlobImports from 'vite-plugin-sass-glob-import';
export default defineConfig({
	server: {
		port: 8080,
		watch: {
			include: ['assets/**/*', 'src/scripts/**/*'],
		},
		hmr: {
			overlay: true,
		},
	},
	build: {
		outDir: 'dist',
		manifest: true,
		external: ['@wordpress/interactivity'], // Exclude interactivity from the bundle
		rollupOptions: {
			input: {
				main: path.resolve(__dirname, 'assets/scripts/main.js'),
				styles: path.resolve(__dirname, 'assets/styles/main.scss'),
				admin: path.resolve(__dirname, 'assets/scripts/admin.js'),
				'admin-styles': path.resolve(__dirname, 'assets/styles/admin.scss'),
			},
			output: {
				chunkFileNames: 'chunks/[name]-[hash].js',
				entryFileNames: 'wp2-[name].js',
				assetFileNames: (assetInfo) => {
					if (!assetInfo.name) {
						return 'assets/[name]-[hash][extname]';
					}

					return `wp2-${assetInfo.name}`;
				},
			}
		},
	},
	resolve: {
		alias: {
			scss: path.resolve(__dirname, 'assets/styles/scss')
		}
	},
	css: {
		preprocessorOptions: {
			scss: {
				silenceDeprecations: [
					'import',
					'color-functions',
					'global-builtin',
				],
			},
		},
	},
	plugins: [
		viteSassGlobImports(),
	],
});
