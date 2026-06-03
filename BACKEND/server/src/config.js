require('dotenv').config();

const nodeEnv = process.env.NODE_ENV || 'development';

if (nodeEnv === 'production' && (!process.env.SESSION_SECRET || process.env.SESSION_SECRET === 'dev-secret-change-me')) {
	throw new Error('SESSION_SECRET must be set to a strong random value in production. Run: node -e "console.log(require(\'crypto\').randomBytes(64).toString(\'hex\'))"');
}

module.exports = {
	port: Number(process.env.PORT || 5001),
	nodeEnv,
	sessionSecret: process.env.SESSION_SECRET || 'dev-secret-change-me',
	frontendOrigins: String(process.env.FRONTEND_ORIGINS || '')
		.split(',')
		.map(v => v.trim())
		.filter(Boolean),
	database: {
		host: process.env.DB_HOST || '127.0.0.1',
		port: Number(process.env.DB_PORT || 3306),
		user: process.env.DB_USER || 'root',
		password: process.env.DB_PASSWORD || '',
		database: process.env.DB_NAME || 'kapitbisig_db',
		connectionLimit: 10,
		waitForConnections: true,
		queueLimit: 0
	}
};
