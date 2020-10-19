# This is necessary for nvm to work.
unset NPM_CONFIG_PREFIX
# Disable npm update notifier; being a read only system it will probably annoy you.
export NO_UPDATE_NOTIFIER=1
# This loads nvm for general usage.
export NVM_DIR="$PLATFORM_APP_DIR/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
nvm use 12
