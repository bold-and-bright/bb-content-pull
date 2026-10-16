(() => {
  // Optional self-update view (bb.contentpull.updates => true). The rest of
  // the plugin is CLI-only.
  const UpdateView = {
    data() {
      return { status: null, loading: true, busy: false, error: null };
    },
    async created() {
      await this.load();
    },
    computed: {
      latest() {
        return this.status && this.status.latest;
      }
    },
    methods: {
      async load(refresh = false) {
        this.loading = true;
        this.error = null;
        try {
          this.status = await this.$api.get("content-pull/update/status", refresh ? { refresh: 1 } : {});
        } catch (error) {
          this.error = error.message;
        } finally {
          this.loading = false;
        }
      },
      async run(action) {
        this.busy = true;
        try {
          const result = await this.$api.post(`content-pull/update/${action}`);
          this.$panel.notification.success(`Content Pull ${result.from} → ${result.to}`);
          // The plugin's own panel code may have changed
          setTimeout(() => window.location.reload(), 800);
        } catch (error) {
          this.$panel.notification.error(error);
          this.busy = false;
        }
      }
    },
    template: `
      <k-panel-inside>
        <k-header>Content Pull Update</k-header>

        <div class="k-content-pull-update-view">
        <k-box v-if="error" theme="negative" :text="error" />

        <template v-if="status">
          <k-box v-if="status.error" theme="notice" :text="'GitHub: ' + status.error" />
          <k-box v-for="warning in status.warnings" :key="warning" theme="notice" :text="warning" />
          <k-box v-if="!status.writable" theme="negative" text="Der Plugin-Ordner ist für PHP nicht beschreibbar – ein Update ist hier nicht möglich." />

          <dl class="k-content-pull-update">
            <dt>Installiert</dt>
            <dd>{{ status.current }}</dd>
            <dt>Aktuell auf GitHub</dt>
            <dd>
              <template v-if="latest">
                {{ latest.version }}
                <a :href="latest.url" target="_blank" rel="noopener">Release ansehen</a>
              </template>
              <template v-else>–</template>
            </dd>
            <template v-if="status.backup">
              <dt>Backup</dt>
              <dd>{{ status.backup }}</dd>
            </template>
          </dl>

          <pre v-if="status.available && latest.notes" class="k-content-pull-update-notes">{{ latest.notes }}</pre>

          <k-button-group>
            <k-button icon="angle-left" link="system">System</k-button>
            <k-button
              v-if="status.available"
              icon="download"
              variant="filled"
              theme="positive"
              :disabled="busy || !status.writable"
              @click="run('install')"
            >
              Auf {{ latest.version }} aktualisieren
            </k-button>
            <k-button
              v-if="status.backup"
              icon="undo"
              variant="filled"
              :disabled="busy || !status.writable"
              @click="run('rollback')"
            >
              Vorherige Version {{ status.backup }} wiederherstellen
            </k-button>
            <k-button icon="refresh" :disabled="busy || loading" @click="load(true)">
              Erneut prüfen
            </k-button>
          </k-button-group>
          <p v-if="!status.available && !loading" class="k-content-pull-update-hint">Content Pull ist aktuell.</p>
        </template>
        </div>
      </k-panel-inside>
    `
  };

  panel.plugin("bb/content-pull", {
    components: {
      "k-content-pull-update-view": UpdateView
    }
  });
})();
