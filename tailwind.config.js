/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./public/**/*.php",
    "./modules/**/*.php",
    "./views/**/*.php",
    "./core/**/*.php",
    "./resources/views/**/*.php",
    "./manuales_web/**/*.php",
    "./public/assets/**/*.js",
    "./*.php",
  ],
  theme: {
    extend: {
      colors: {
        primary: {
          50: '#e6edf5',
          100: '#b3c9e0',
          200: '#80a5cb',
          300: '#4d81b6',
          400: '#2663a0',
          500: '#1a365d',
          600: '#152b4a',
          700: '#0f2037',
          800: '#0a1628',
          900: '#050d14',
        },
        accent: '#48bb78',
        accentDark: '#38a169',
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
