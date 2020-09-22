cd ~/platformsh/clients
git clone git@github.com:devicezero/platformsh-shopware6.git
git clone git@github.com:shopware/production.git
cp -R shopware6-diff/* production
cd production
git apply composer.diff
rm -rf .git
rm composer.diff composer.lock instructions.md
composer install
rsync -av --progress --delete --exclude 'vendor' --exclude '.git' . ../platformsh-shopware6
rm -rf production
