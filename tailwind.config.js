/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './templates/**/*.twig',
    './public/**/*.js',
  ],
  theme: {
    extend: {
      colors: {
        // Palette Figma (Primitives)
        fond: '#0a0a0a', // neutral/950 — fond page
        carte: '#171717', // neutral/900 — fond carte
        bordure: 'rgba(38,38,38,0.4)',
        texte: '#fafafa', // neutral/50
        muted: '#737373', // neutral/500
        muted2: '#525252', // neutral/600
        pin: '#ca3500', // orange/700
        ferme: { DEFAULT: '#ff6467', fond: '#460809' }, // red 400 / 950
        degage: { DEFAULT: '#05df72', fond: '#032e15' }, // green 400 / 950
        perturbe: { DEFAULT: '#fcc800', fond: '#432004' }, // yellow 400 / 950
      },
      fontFamily: {
        sans: ['SF Pro', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
