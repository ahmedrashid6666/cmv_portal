import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            // CMV Shipping brand palette (from the company website)
            colors: {
                primary: {
                    50: '#f0fffe',
                    100: '#e0fcfc',
                    200: '#b3f5f6',
                    300: '#85eef0',
                    400: '#4ce8eb',
                    500: '#1b9a9b',
                    600: '#158a8b',
                    700: '#107a7b',
                    800: '#0d6a6b',
                    900: '#0a5a5b',
                    950: '#054545',
                },
                navy: {
                    50: '#f5f7fb',
                    100: '#ebeef6',
                    200: '#d1d9ed',
                    300: '#b7c4e3',
                    400: '#7d94ce',
                    500: '#4364b8',
                    600: '#1e3a5f',
                    700: '#1a324f',
                    800: '#152a3f',
                    900: '#10222f',
                    950: '#0a141f',
                },
                accent: {
                    red: '#E63946',
                    'red-light': '#F15060',
                    'red-dark': '#D62828',
                },
            },
        },
    },

    plugins: [forms],
};
