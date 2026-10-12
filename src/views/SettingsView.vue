<template>
	<div class="settings">
		<NcSettingsSection
			:name="t('koreader_companion', 'eBooks folder')"
			:description="t('koreader_companion', 'Where your library lives. Changing it clears the index and re-scans; reading progress is kept.')">
			<div class="settings__row">
				<!-- :model-value, not :value -- v9 renamed the prop to modelValue, and a
				     :value binding is silently ignored, leaving the field blank.
				     readonly rather than disabled so the current folder stays legible. -->
				<NcTextField
					:model-value="folder"
					:label="t('koreader_companion', 'Folder')"
					:readonly="true"
					class="settings__folder" />
				<NcButton @click="pickFolder">
					<template #icon>
						<FolderOutline :size="20" />
					</template>
					{{ t('koreader_companion', 'Browse') }}
				</NcButton>
			</div>
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('koreader_companion', 'Filenames')"
			:description="t('koreader_companion', 'Rename files from their metadata, as “Author - Title (Year).ext”.')">
			<NcCheckboxRadioSwitch
				:model-value="autoRename"
				type="switch"
				@update:model-value="onAutoRename">
				{{ t('koreader_companion', 'Rename new files automatically') }}
			</NcCheckboxRadioSwitch>

			<NcButton
				:disabled="renaming"
				class="settings__rename"
				@click="confirmRename">
				<template #icon>
					<NcLoadingIcon v-if="renaming" :size="20" />
					<Pencil v-else :size="20" />
				</template>
				{{ t('koreader_companion', 'Rename all existing books now') }}
			</NcButton>

			<NcProgressBar v-if="renaming" :value="renameProgress" size="medium" class="settings__progress" />
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('koreader_companion', 'OPDS Optimization')"
			:description="t('koreader_companion', 'Shrink oversized images in new EPUBs before OPDS clients (like KOReader) download them. The in-app reader always uses the original file.')">
			<NcCheckboxRadioSwitch
				:model-value="optimizeEnabled"
				type="switch"
				@update:model-value="onOptimizeEnabledChange">
				{{ t('koreader_companion', 'Optimize new EPUBs for OPDS') }}
			</NcCheckboxRadioSwitch>

			<div class="settings__row">
				<NcTextField
					:model-value="String(optimizeMaxWidth)"
					:disabled="!optimizeEnabled"
					type="number"
					:label="t('koreader_companion', 'Max image width (px)')"
					class="settings__optimize-field"
					@update:model-value="optimizeMaxWidth = $event" />
				<NcTextField
					:model-value="String(optimizeMaxHeight)"
					:disabled="!optimizeEnabled"
					type="number"
					:label="t('koreader_companion', 'Max image height (px)')"
					class="settings__optimize-field"
					@update:model-value="optimizeMaxHeight = $event" />
			</div>

			<NcCheckboxRadioSwitch
				:model-value="optimizeGrayscale"
				type="switch"
				:disabled="!optimizeEnabled"
				@update:model-value="onOptimizeGrayscaleChange">
				{{ t('koreader_companion', 'Convert images to grayscale') }}
			</NcCheckboxRadioSwitch>

			<NcButton
				:disabled="savingOptimize"
				class="settings__rename"
				@click="saveOptimizeSettings">
				<template #icon>
					<NcLoadingIcon v-if="savingOptimize" :size="20" />
					<ContentSaveOutline v-else :size="20" />
				</template>
				{{ t('koreader_companion', 'Save and rebuild opds-optimized library') }}
			</NcButton>
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('koreader_companion', 'Karakeep import')"
			:description="t('koreader_companion', 'Save the articles of one Karakeep list as EPUBs in your library. New items are picked up every few minutes.')">
			<NcCheckboxRadioSwitch
				:model-value="karakeepEnabled"
				type="switch"
				@update:model-value="onKarakeepEnabledChange">
				{{ t('koreader_companion', 'Import articles from Karakeep') }}
			</NcCheckboxRadioSwitch>

			<div class="settings__row">
				<NcTextField
					v-model="karakeepUrl"
					:disabled="!karakeepEnabled"
					:label="t('koreader_companion', 'Karakeep URL')"
					class="settings__karakeep-field" />
				<NcTextField
					v-model="karakeepListId"
					:disabled="!karakeepEnabled"
					:label="t('koreader_companion', 'List id')"
					class="settings__karakeep-field" />
			</div>

			<div class="settings__row">
				<NcPasswordField
					v-model="karakeepApiKey"
					:disabled="!karakeepEnabled"
					:label="karakeepHasApiKey ? t('koreader_companion', 'API key (leave empty to keep the saved one)') : t('koreader_companion', 'API key')"
					class="settings__karakeep-field" />
			</div>

			<div class="settings__row">
				<NcButton
					:disabled="savingKarakeep"
					class="settings__rename"
					@click="saveKarakeepSettings">
					<template #icon>
						<NcLoadingIcon v-if="savingKarakeep" :size="20" />
						<ContentSaveOutline v-else :size="20" />
					</template>
					{{ t('koreader_companion', 'Save') }}
				</NcButton>
				<NcButton
					:disabled="syncingKarakeep || !karakeepEnabled"
					class="settings__rename"
					@click="runKarakeepSync">
					<template #icon>
						<NcLoadingIcon v-if="syncingKarakeep" :size="20" />
						<Sync v-else :size="20" />
					</template>
					{{ t('koreader_companion', 'Import now') }}
				</NcButton>
			</div>
		</NcSettingsSection>
	</div>
</template>

<script>
import { FilePickerClosed, getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Sync from 'vue-material-design-icons/Sync.vue'

import {
	batchRename,
	getBatchRenameProgress,
	getSettings,
	setAutoRename,
	setFolder,
	setKarakeep,
	setOpdsOptimize,
	syncKarakeep,
} from '../api.js'

export default {
	name: 'SettingsView',

	components: {
		ContentSaveOutline,
		FolderOutline,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcPasswordField,
		NcProgressBar,
		NcSettingsSection,
		NcTextField,
		Pencil,
		Sync,
	},

	data() {
		return {
			folder: 'eBooks',
			autoRename: false,
			renaming: false,
			renameProgress: 0,
			poll: null,
			optimizeEnabled: true,
			optimizeMaxWidth: 1600,
			optimizeMaxHeight: 2400,
			optimizeGrayscale: false,
			savingOptimize: false,
			karakeepEnabled: false,
			karakeepUrl: '',
			karakeepListId: '',
			karakeepApiKey: '',
			karakeepHasApiKey: false,
			savingKarakeep: false,
			syncingKarakeep: false,
		}
	},

	async mounted() {
		try {
			const settings = await getSettings()
			this.folder = settings.folder || 'eBooks'
			this.autoRename = settings.auto_rename === 'yes'
			this.optimizeEnabled = settings.opds_optimize_enabled !== 'no'
			this.optimizeMaxWidth = parseInt(settings.opds_optimize_max_width, 10) || 1600
			this.optimizeMaxHeight = parseInt(settings.opds_optimize_max_height, 10) || 2400
			this.optimizeGrayscale = settings.opds_optimize_grayscale === 'yes'
			this.karakeepEnabled = settings.karakeep_enabled === 'yes'
			this.karakeepUrl = settings.karakeep_url || ''
			this.karakeepListId = settings.karakeep_list_id || ''
			this.karakeepHasApiKey = settings.karakeep_has_api_key === 'yes'
		} catch (error) {
			showError(t('koreader_companion', 'Could not load settings'))
		}
	},

	beforeUnmount() {
		clearInterval(this.poll)
	},

	methods: {
		async pickFolder() {
			// Replaces OC.dialogs.filepicker, deprecated since 27.1. The
			// confirm button is load-bearing: dialogs renders no default
			// action, so without addButton the picker can only be cancelled
			// -- pick() then rejects and the folder is never set.
			//
			// No MIME filter: a books folder holds files, not subfolders, so
			// a directory-only mask renders the target as an empty list
			// ("No files here" -- the files are there, just hidden). canPick
			// shows them but keeps them unpickable instead.
			const picker = getFilePickerBuilder(t('koreader_companion', 'Choose your eBooks folder'))
				.allowDirectories(true)
				.setMultiSelect(false)
				.setCanPick((node) => node.type === 'folder')
				.addButton({
					label: t('koreader_companion', 'Choose'),
					variant: 'primary',
					// The library closes the dialog with the selected nodes
					// after the callback; picking needs no extra work here.
					callback: () => {},
				})
				.build()

			try {
				const paths = await picker.pick()
				const path = Array.isArray(paths) ? paths[0] : paths
				if (!path) {
					return
				}
				const folder = String(path).replace(/^\/+/, '') || 'eBooks'
				const result = await setFolder(folder)
				this.folder = folder
				showSuccess(result?.message || t('koreader_companion', 'Folder updated'))
			} catch (error) {
				// Cancel rejects with FilePickerClosed; not worth a toast.
				if (!(error instanceof FilePickerClosed)) {
					showError(t('koreader_companion', 'Could not change the folder'))
				}
			}
		},

		async onAutoRename(value) {
			this.autoRename = value
			try {
				await setAutoRename(value)
			} catch (error) {
				this.autoRename = !value
				showError(t('koreader_companion', 'Could not save that setting'))
			}
		},

		async onOptimizeEnabledChange(value) {
			this.optimizeEnabled = value
			await this.saveOptimizeSettings()
		},

		async onOptimizeGrayscaleChange(value) {
			this.optimizeGrayscale = value
			await this.saveOptimizeSettings()
		},

		/** Width/height only commit here (button or a switch), not on every keystroke. */
		async saveOptimizeSettings() {
			this.savingOptimize = true
			try {
				await setOpdsOptimize({
					enabled: this.optimizeEnabled,
					maxWidth: this.optimizeMaxWidth,
					maxHeight: this.optimizeMaxHeight,
					grayscale: this.optimizeGrayscale,
				})
				showSuccess(t('koreader_companion', 'OPDS optimization settings saved'))
			} catch (error) {
				showError(t('koreader_companion', 'Could not save that setting'))
			} finally {
				this.savingOptimize = false
			}
		},

		async onKarakeepEnabledChange(value) {
			this.karakeepEnabled = value
			await this.saveKarakeepSettings()
		},

		/** Text fields only commit here (button or the switch), not on every keystroke. */
		async saveKarakeepSettings() {
			this.savingKarakeep = true
			try {
				const result = await setKarakeep({
					enabled: this.karakeepEnabled,
					url: this.karakeepUrl,
					apiKey: this.karakeepApiKey,
					listId: this.karakeepListId,
				})
				this.karakeepHasApiKey = !!result?.has_api_key
				this.karakeepApiKey = ''
				showSuccess(t('koreader_companion', 'Karakeep settings saved'))
			} catch (error) {
				showError(error?.response?.data?.error || t('koreader_companion', 'Could not save that setting'))
			} finally {
				this.savingKarakeep = false
			}
		},

		async runKarakeepSync() {
			this.syncingKarakeep = true
			try {
				const result = await syncKarakeep()
				showSuccess(t('koreader_companion', 'Imported {count} articles', { count: result?.imported ?? 0 }))
			} catch (error) {
				showError(error?.response?.data?.error || t('koreader_companion', 'Import failed'))
			} finally {
				this.syncingKarakeep = false
			}
		},

		async confirmRename() {
			this.renaming = true
			this.renameProgress = 0
			this.poll = setInterval(async () => {
				try {
					const progress = await getBatchRenameProgress()
					this.renameProgress = Number(progress?.percent) || 0
				} catch (error) {
					// Progress is advisory; a failed poll should not abort the rename.
				}
			}, 1000)

			try {
				const result = await batchRename()
				showSuccess(t('koreader_companion', 'Renamed {count} of {total} books', {
					count: result?.renamed_count ?? 0,
					total: result?.total_books ?? 0,
				}))
			} catch (error) {
				showError(t('koreader_companion', 'Renaming failed'))
			} finally {
				clearInterval(this.poll)
				this.renaming = false
				this.renameProgress = 0
			}
		},
	},
}
</script>

<style scoped lang="scss">
.settings {
	&__row {
		display: flex;
		align-items: end;
		gap: calc(var(--default-grid-baseline) * 2);
		flex-wrap: wrap;

		> .button-vue {
			flex: 0 0 auto;
		}
	}

	&__folder {
		flex: 1 1 240px;
		max-width: 360px;
	}

	&__rename {
		margin-block-start: calc(var(--default-grid-baseline) * 3);
	}

	&__progress {
		margin-block-start: calc(var(--default-grid-baseline) * 2);
		max-width: 360px;
	}

	&__karakeep-field {
		flex: 1 1 240px;
		max-width: 360px;
	}

	&__optimize-field {
		flex: 1 1 160px;
		max-width: 200px;
	}
}
</style>
