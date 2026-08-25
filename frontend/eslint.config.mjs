import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";

const eslintConfig = defineConfig([
  ...nextVitals,
  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
  ]),
  {
    rules: {
      /*
       * Product photos, logos and banners are served from whatever backend
       * NEXT_PUBLIC_BACKEND_URL points at, which changes per environment.
       * next/image needs every host declared at build time, so plain <img>
       * is the deliberate choice here. Add hosts to next.config.mjs and
       * switch to <Image /> if you want automatic optimisation.
       */
      "@next/next/no-img-element": "off",
    },
  },
]);

export default eslintConfig;
