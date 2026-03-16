'use strict';

const { createServer } = require('./bootstrap/create-server');

async function start() {
  const server = await createServer();
  await server.start();
  // eslint-disable-next-line no-console
  console.log(`Server running at: ${server.info.uri}`);
}

process.on('unhandledRejection', (err) => {
  // eslint-disable-next-line no-console
  console.error(err);
  process.exit(1);
});

if (require.main === module) {
  start();
}

module.exports = {
  createServer
};
